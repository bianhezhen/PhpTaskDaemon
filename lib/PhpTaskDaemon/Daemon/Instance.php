<?php
/**
 * @package PhpTaskDaemon
 * @subpackage Core
 * @copyright Copyright (C) 2010 Dirk Engels Websolutions. All rights reserved.
 * @author Dirk Engels <d.engels@dirkengels.com>
 * @license https://github.com/DirkEngels/PhpTaskDaemon/blob/master/doc/LICENSE
 */

namespace PhpTaskDaemon\Daemon;

/**
 * 
 * The run object starts the main process of the daemon. The process is forked 
 * for each task manager.
 *
 */
class Daemon {
	/**
	 * This variable contains pid manager object
	 * @var Manager $_pidManager
	 */
	protected $_pidManager = null;
	
	/**
	 * Pid reader object
	 * @var File $_pidFile
	 */
	protected $_pidFile = null;
	
	/**
	 * Shared memory object
	 * @var SharedMemory $_shm
	 */
	protected $_shm = null;
	
	/**
	 * Logger object
	 * @var Zend_Log $_log
	 */
	protected $_log = null;
	
	/**
	 * Array with managers
	 * @var array $_managers
	 */
	protected $_managers = array();
	
	/**
	 * 
	 * The construction has one optional argument containing the parent process
	 * ID. 
	 * @param int $parent
	 */
	public function __construct($parent = null) {
		$pidFile = \TMP_PATH . '/' . strtolower(str_replace('\\', '-', get_class($this))) . 'd.pid';
		$this->_pidManager = new \PhpTaskDaemon\Daemon\Pid\Manager(getmypid(), $parent);
		$this->_pidFile = new \PhpTaskDaemon\Daemon\Pid\File($pidFile);
		
		$this->_shm = new \PhpTaskDaemon\Daemon\Ipc\SharedMemory('daemon');
		$this->_shm->setVar('state', 'running');
		
		$this->_initLogSetup();
	}
	
	/**
	 * 
	 * Unset variables at destruct to hopefully free some memory. 
	 */
	public function __destruct() {
//		$this->_shm->setVar('state', 'stopped');
		unset($this->_pidManager);
		unset($this->_pidFile);
//		unset($this->_shm);
	}

	/**
	 * 
	 * Returns the log object
	 * @return Zend_Log
	 */
	public function getLog() {
		return $this->_log;
	}

	/**
	 * 
	 * Sets the log object
	 * @param Zend_Log $log
	 * @return $this
	 */
	public function setLog(Zend_Log $log) {
		$this->_log = $log;
		return $this;
	}

	/**
	 * 
	 * Returns the log file to use. It tries a few possible options for
	 * retrieving or composing a valid logfile.
	 * @return string
	 */
	protected function _getLogFile() {
		$logFile = TMP_PATH . '/' . strtolower(get_class($this)) . 'd.log';
		return $logFile;
	}

	/**
	 *
	 * Initialize logger with a null writer. Null writer is needed because this function
	 * is invoked very early in the bootstrap.
	 * 
	 * @param Zend_Log $log
	 */
	protected function _initLogSetup(Zend_Log $log = null) {
		if (is_null($log)) {
			$log = new \Zend_Log();
		}

		$writerNull = new \Zend_Log_Writer_Null;
		$log->addWriter($writerNull);
		
		$this->_log = $log;
	}

	/**
	 * 
	 * Add additional (zend) log writers
	 */
	protected function _initLogOutput() {
		// Add writer: verbose		
		$logFile = $this->_getLogFile();
		if (!file_exists($logFile)) {
			touch($logFile);
		}
		
		$writerFile = new \Zend_Log_Writer_Stream($logFile);
		$this->_log->addWriter($writerFile);
		$$this->_log->log('Adding log file: ' . $logFile, \Zend_Log::DEBUG);
	}


	/**
	 * 
	 * Adds a manager object to the managers stack
	 * @param Manager\AbstractClass $manager
	 */
	public function addManager(Manager\AbstractClass $manager) {
		return array_push($this->_managers, $manager);
	}

	/**
	 * 
	 * Creates a manager based on the task definition and adds it to the stack.
	 * @param Task\AbstractClass $task
	 */
	public function addManagerByTask(Task\AbstractClass $task) {
		$managerType = $task::getManagerType();
		$managerClass = 'Manager\\' . $managerType;
		if (class_exists($managerClass)) {
			$manager = new $managerClass();
		} else {
			$manager = new \PhpTaskDaemon\Manager\Interval();
		}
		
		$manager->setTask($task);
		$this->addManager($manager);
		return $this;
	}

	/**
	 * 
	 * Scans a directory for task managers
	 * 
	 * @param string $dir
	 */
	public function scanTaskDirectory($dir) {
		$$this->_log->log("Scanning directory for tasks: " . $dir, \Zend_Log::DEBUG);

		if (!is_dir($dir)) {
			throw new \Exception('Directory does not exists');
		}

		$files = scandir($dir);
		$countLoadedObjects = 0;
		foreach($files as $file) {
			if (preg_match('/(.*)+\.php$/', $file, $match)) {
				require_once($dir . '/' . $file);
				$taskClass = substr(get_class($this), 0, -7) . '\\Task\\' . preg_replace('/\.php$/', '', $file);
				$$this->_log->log("Checking task: " . $taskClass, \Zend_Log::DEBUG);
				if (class_exists($taskClass)) {
					$$this->_log->log("Adding task: " . $taskClass . ' (' . $taskClass::getManagerType() . ')', \Zend_Log::INFO);
					$task = new $taskClass();
					$this->addManagerByTask($task);
					$countLoadedObjects++;
				}
			}
		}
		return $countLoadedObjects;
	}
	
	/**
	 * 
	 * This is the public start function of the daemon. It checks the input and
	 * available managers before running the daemon.
	 */
	public function start() {
		$this->_pidFile->writePidFile($this->_pidManager->getCurrent());
		$this->_initLogOutput();

		// Check input here
		$this->scanTaskDirectory(APPLICATION_PATH . '/daemon/');
		
		// All valid
		$this->_run();
	}

	/**
	 * 
	 * POSIX Signal handler callback
	 * @param $sig
	 */
	public function sigHandler($sig) {
		switch ($sig) {
			case SIGTERM:
				// Shutdown
				$$this->_log->log('Application (DAEMON) received SIGTERM signal (shutting down)', \Zend_Log::DEBUG);
				exit;
				break;
			case SIGCHLD:
				// Halt
				$$this->_log->log('Application (DAEMON) received SIGCHLD signal (halting)', \Zend_Log::DEBUG);		
				while (pcntl_waitpid(-1, $status, WNOHANG) > 0);
				break;
			case SIGINT:
				// Shutdown
				$$this->_log->log('Application (DAEMON) received SIGINT signal (shutting down)', \Zend_Log::DEBUG);
				break;
			default:
				$$this->_log->log('Application (DAEMON) received ' . $sig . ' signal (unknown action)', \Zend_Log::DEBUG);
				break;
		}
	}

	/**
	 * 
	 * This is the main function for running the daemon!
	 */
	protected function _run() {
		// All OK.. Let's go
		declare(ticks = 1);

		if (count($this->_managers)==0) {
			$$this->_log->log("No daemon tasks found", \Zend_Log::INFO);
			exit;
		}
		$$this->_log->log("Starting daemon tasks", \Zend_Log::DEBUG);
		foreach ($this->_managers as $manager) {
			$manager->setLog(clone($this->_log));
			$$this->_log->log("Forking manager: "  . get_class($manager), \Zend_Log::INFO);
			$this->_forkManager($manager);
		}
		
		// Default sigHandler
		$$this->_log->log("Setting default sighanler", \Zend_Log::DEBUG);
		$this->_sigHandler = new Interupt\SignalHandler(
			'Main Daemon',
			$this->_log,
			array(&$this, 'sigHandler')
		);
		
		// Write pids to shared memory
		$this->_shm->setVar('childs', $this->_pidManager->getChilds());
	
		// Wait till all childs are done
	    while (pcntl_waitpid(0, $status) != -1) {
        	$status = pcntl_wexitstatus($status);
        	$$this->_log->log("Child $status completed");
    	}
		$$this->_log->log("Running done.", \Zend_Log::NOTICE);

		$this->_pidFile->unlinkPidFile();
		$this->_shm->remove();

		exit;
	}

	/**
	 * 
	 * Fork the managers to be processed in the background. The foreground task
	 * is used to add gearman tasks to the queue for the background gearman 
	 * managers
	 */
	private function _forkManager($manager)
	{
		$pid = pcntl_fork();
		if ($pid === -1) {
			// Error
			$$this->_log->log('Managers could not be forked!!!', \Zend_Log::CRIT);
			return false;

		} elseif ($pid) {
			// Parent
			$this->_pidManager->addChild($pid);

		} else {
			// Child
			$newPid = getmypid();
			$this->_pidManager->forkChild($newPid);
			$manager->init($this->_pidManager->getParent());
//			
			
			$$this->_log->log('Manager forked (PID: ' . $newPid . ') !!!', \Zend_Log::DEBUG);
			$manager->runManager();
			exit;
		}
	}

}