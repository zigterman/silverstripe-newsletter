<?php
/**
 * A class that controls the queuing and processing of emails. When a user clicks the Send button in a Newsletter Admin
 * that uses this class to add the send-out to a queue. The queue doesn't contain the actual emails, instead it has
 * a simple handler that calls the "process_queue_invoke" method in this class, a method that does all the actual send
 * out. Send out happens in batches to avoid the process crashing due to exceeding either the memory limit or the
 * execution time limit.
 * Once a batch of emails has been sent out, then another "process_queue_invoke" method call is queue in the
 * MessageQueue and it keeps processing with a fresh PHP instance.
 * If this process ever fails for any reason, you can call this manually as a build task:
 * /dev/tasks/NewsletterSendController?newsletter=#
 * (where '#' is the database ID of any Newsletter DataObject).
 *
 * You can use the following static variables to configure the NewsletterSendController:
 * static $items_to_batch_process = 50;   //number of emails to send out in "batches" to avoid spin up costs
 * static $stuck_timeout = 5;  //minutes after which we consider an "InProcess" item in the queue "stuck"
 * static $retry_limit = 4; //number of times to retry sending email that get "stuck"
 * static $throttle_batch_delay = 0;   //seconds to wait between sending out email batches
 */
class NewsletterSendController extends BuildTask {

	static $items_to_batch_process = 50;   //number of emails to send out in "batches" to avoid spin up costs
	static $stuck_timeout = 5;  //minutes after which we consider an "InProcess" item in the queue "stuck"
	static $retry_limit = 4; //number of times to retry sending email that get "stuck"
	static $throttle_batch_delay = 0;   //seconds to wait between sending out email batches

	protected static $inst = null;

	protected $title = 'Newsletter Send Controller';
	protected $description = 'Triggers processing of the send queue the specific newsletter ID.
		Usage: dev/tasks/NewsletterSendController?newsletter=#';

	static function inst() {
		if(!self::$inst) self::$inst = new NewsletterSendController();
		return self::$inst;
	}

	/**
	 * Adds users to the queue for sending out a newsletter.
	 * Processed all users that are CURRENTLY in the mailing lists associated with this MailingList and adds them
	 * to the queue.
	 * @static
	 * @param $id The ID of the Newsletter DataObject to send
	 */
	function enqueue(Newsletter $newsletter) {
		$lists = $newsletter->MailingLists();
		$queueCount = 0;
		foreach($lists as $list) {
			foreach($list->Recipients() as $recipient) {
				$queueItem = SendRecipientQueue::create();
				$queueItem->NewsletterID = $newsletter->ID;
				$queueItem->RecipientID = $recipient->ID;
				$queueItem->write();
				$queueCount++;
			}
		}

		return $queueCount;
	}

	function processQueueOnShutdown($newsletterID = null) {
		if (class_exists('MessageQueue')) {
			if (!empty($newsletterID)) {
				//start processing of email sending for this newsletter ID after shutdown
				MessageQueue::send("newsletter",
					new MethodInvocationMessage('NewsletterSendController', "process_queue_invoke", $newsletterID));
			} else {
				user_error("No newsletter ID given",E_USER_ERROR);
			}

			MessageQueue::consume_on_shutdown();
		} else {
			//do the sending in real-time, if there is not MessageQueue to do it out-of-process
			$this->processQueue($newsletterID);
		}
	}

	/**
	 * Restart the processing of any queue items that are "stuck" in the InProcess status, but haven't been sent.
	 * Items can get stuck if the execution of the newsletter queue fails half-way due to an error. Restarting
	 * the queue processing takes the items and re-schedules them for a new send out. If a specific Recipient in the
	 * queue is causing the crashes, then the RetryCount for that item will go up on each retry attempt. This method
	 * will eventually stop re-scheduling items if their retry count gets too high, indicating such a problem.
	 *
	 * @param $newsletterID
	 * @return Int the number of stuck items re-added to the queue
	 */
	function cleanUpStalledQueue($newsletterID) {
		$stuckQueueItems = SendRecipientQueue::get()->filter(array(
			'NewsletterID' => $newsletterID,
			'Status' => 'InProcess',
			'LastEdited:LessThan' => date('Y-m-d H:i:m',strtotime('-'.self::$stuck_timeout.' minutes'))
		));

		$stuckCount = $stuckQueueItems->Count();
		if ($stuckCount  > 0) {
			foreach($stuckQueueItems as $item){
				if ($item->RetryCount < self::$retry_limit) {
					$item->RetryCount = $item->RetryCount + 1;
					$item->Status = "Scheduled";    //retry the item
					$item->write();
				} else {    //enough retries, stop this email and mark it as failed
					$item->Status = "Failed";
					$item->write();
				}
			}
		}

		return $stuckCount;
	}

	static function process_queue_invoke($newsletterID){
		$nsc = NewsletterSendController::inst();
		$nsc->processQueue($newsletterID);
	}

	/**
	 * Start the processing with a build task
	 */
	public function run($request){
		$newsletterID = $request->getVar('newsletter');
		if (!empty($newsletterID) && is_numeric($newsletterID)) {
			$nsc = self::inst();
			$nsc->processQueueOnShutdown($newsletterID);
			$newsletter = Newsletter::get()->byID($newsletterID);
			echo "<h2>Queued sendout for newsletter: $newsletter->Subject (ID: $newsletter->ID)</h2>";
		} else {
			user_error("Usage: dev/tasks/NewsletterSendController?newsletter=#");
		}
	}

	function processQueue($newsletterID){
		set_time_limit(0);  //no time limit for running process

		if (!empty($newsletterID)) {
			$newsletter = Newsletter::get()->byID($newsletterID);
			if (!empty($newsletter)) {
				//try to clean up any stuck items
				$this->cleanUpStalledQueue($newsletterID);

				// Start a transaction, or if we are in MySQL, create a lock on the SendRecipientQueue table.
				$conn = DB::getConn();
				if ($conn instanceof MySQLDatabase) $conn->query('lock table SendRecipientQueue write');
				else if (method_exists($conn, 'startTransaction')) $conn->startTransaction();

				$queueItems = null;
				try {
					//get the first X items to process
					$queueItems = SendRecipientQueue::get()
							->filter(array('NewsletterID' => $newsletterID, 'Status' => 'Scheduled'))
							->sort('Created ASC')
							->limit(self::$items_to_batch_process);

					//set them all to "in process" at once
					foreach($queueItems as $item){
						$item->Status = 'InProcess';
						$item->write();
					}

					// Commit transaction, or in MySQL just release the lock
					if ($conn instanceof MySQLDatabase) $res = $conn->query('unlock tables');
					else if (method_exists($conn, 'endTransaction')) $conn->endTransaction();
				} catch (Exception $e) {
					// Rollback, or in MySQL just release the lock
					if ($conn instanceof MySQLDatabase) $res = $conn->query('unlock tables');
					else if (method_exists($conn, 'transactionRollback')) $conn->transactionRollback();

					//retry the processing
					$this->processQueueOnShutdown($newsletterID);
				}

				//do the actual mail out
				if (!empty($queueItems) && $queueItems->Count() > 0) {
					//fetch all the recipients at once in one query
					$recipients = Recipient::get()->filter(array('ID' => $queueItems->column('RecipientID')));

					$recipientsMap = array();
					foreach($recipients as $r) {
						$recipientsMap[$r->ID] = $r;
					}

					//send out the mails
					foreach($queueItems as $item) {
						$item->send($newsletter, $recipientsMap[$item->RecipientID]);
					}

					//do more processing, in case there are more items to process, do nothing if we've reached the end
					$this->processQueueOnShutdown($newsletterID);

					//wait to avoid overloading the email server with too many emails that look like spam
					if (!empty(self::$throttle_batch_delay)) sleep(self::$throttle_batch_delay);
				} else {
					//mark the send process as complete
					$newsletter->SentDate = SS_Datetime::now()->getValue();
					$newsletter->Status = 'Sent';
					$newsletter->write();
				}
			}
		}
	}

}