<?php

	//FIND THE VISITORS IP       
	function SWDF_get_ip() {
		if (getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), "unknown")) {
			$rip = getenv("HTTP_CLIENT_IP");
		} else if (getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), "unknown")) {
			$rip = getenv("HTTP_X_FORWARDED_FOR");
		} else if (getenv("REMOTE_ADDR") && strcasecmp(getenv("REMOTE_ADDR"), "unknown")) {
			$rip = getenv("REMOTE_ADDR");
		} else if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], "unknown")) {
			$rip = $_SERVER['REMOTE_ADDR'];
		} else {
			$rip = "unknown";
		}
		return $rip;
	}
	
	function SWDF_get_browser() {
		return $_SERVER['HTTP_USER_AGENT'];
	}
	
	function SWDF_check_cookies() {
		global $_SWDF;
		//Default Value, worth a shot (may be determined later)
		$_SWDF['info']['cookies_enabled'] = true;

		//If we were the refferer, there should be a value set in $_SESSION
		if (isset($_SERVER['HTTP_REFERER']) && ((strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) === 7) || (strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) === 8)) ) {
			//The reffer for this page is another page on this site, meaning the session should have been initiated previously

			//Check for session init
			if (isset($_SESSION['_SWDF']['init'])) {
				$_SWDF['info']['cookies_enabled'] = true;
			} else {
				$_SWDF['info']['cookies_enabled'] = false;
			}
		}

		return $_SWDF['info']['cookies_enabled'];
	}
	
	function SWDF_check_session_security(){
		global $_SWDF;
		
		//////////////////////////////////////
		//Persistant Session ID
		//This is used to identify a session internally and span any PHPSESSIDs assigned to it (which can change without notice)
		if (!isset($_SESSION['_SWDF']['info']['persistant_session_id']) || $_SESSION['_SWDF']['info']['persistant_session_id']==""){
			$_SESSION['_SWDF']['info']['persistant_session_id']=hash("sha256", uniqid().mt_rand());
			
			//As this is obviously the first request this session, setup default states for session variables
			$_SESSION['_SWDF']['info']['requests_this_session']=0;
		}

		//Session Hijack Detection
		//The following code is designed to detect unusal behaviour which might indicate a user's session has been hijacked, and take action to prevent it.

		//////////////////////////////////////
		//Check to see if the HTTP_USER_AGENT string for this request is the same as the first request this user made
		if (!isset($_SESSION['_SWDF']['info']['limit_to_browser'])){
			$_SESSION['_SWDF']['info']['limit_to_browser']=true;
		}
		if (isset($_SESSION['_SWDF']['info']['browser']) && $_SESSION['_SWDF']['info']['browser']!="" && $_SESSION['_SWDF']['info']['limit_to_browser']!==false){
			if ($_SESSION['_SWDF']['info']['browser'] != SWDF_get_browser()){
				//We seem to be using a differnt browser all of a sudden - someone might be trying to hijack the session.

				//Spawn a new blank session and kick the user into that
				session_regenerate_id();
				session_unset();

				//Let the user know what just happened
				header( "Location: " . make_link($_SWDF['settings']['on_auth_failure'], array("reason"=>"wrong_browser"), true)); exit;
			}
		} else {
			//This is probably the user's first request. Store the current HTTP_USER_AGENT in the $_SESSION variable.
			$_SESSION['_SWDF']['info']['browser'] = SWDF_get_browser();
		}

		//////////////////////////////////////
		//Check to see if the IP address for this request is the same as the first request this user made after logging in
		if (isset($_SESSION['_SWDF']['info']['limit_to_ip']) && $_SESSION['_SWDF']['info']['limit_to_ip']!=false){
			if ($_SESSION['_SWDF']['info']['limit_to_ip']!=SWDF_get_ip()){
				//We seem to be using a from a different IP address all of a sudden - someone might be trying to hijack the session.
				
				//Spawn a new blank session and kick the user into that
				session_regenerate_id();
				$_SESSION[]=Array();
				
				//Let the user know what just happened
				header( "Location: " . make_link($_SWDF['settings']['on_auth_failure'], array("reason"=>"wrong_ip"), true)); exit;
			}
		}
		
		//////////////////////////////////////
		//If this is the user's first request this session, assign a random PHPSESSID in case php.ini allows a malcious link to set one this through the request string.
		if (!isset($_SESSION['_SWDF']['init'])){
			session_regenerate_id(true);
			$_SESSION['_SWDF']['init']=true;
		}

		//////////////////////////////////////
		//If user arrived from an outside source automatically assign them a new PHPSESSID to prevent their PHPSESSID being known by a third-part
		if (isset($_SERVER['HTTP_REFERER']) && stripos($_SERVER['HTTP_HOST'], $_SERVER['HTTP_REFERER'])!==false){
			session_regenerate_id(true);
		}	
	}
	
	class SWDF_session_db_handler {
	
		/*
			To use the database to store session data initiate this class.
			You must either pass an active instance of PDO as the first paramiter, 
			or pass the conection string, username and password.
		*/
		
		public $conn;
		private $user;
		private $pass;
		private $conn_string;
		public $using;
		
		public function __construct($connection,$user=NULL,$pass=NULL){
			if (is_object($connection) && ( is_subclass_of($connection, "PDO") || get_class($connection)=="PDO" ) ){
				$this->conn=&$connection;
				$this->using="passed";

			} else {
				$this->conn_string=$connection;
				$this->user=$user;
				$this->pass=$pass;
				$this->using="new";
			}
			
			if (session_set_save_handler(
				array(&$this,'open'),
				array(&$this,'close'),
				array(&$this,'read'),
				array(&$this,'write'),
				array(&$this,'destroy'),
				array(&$this,'clean')
			)){
				return true;
			} else {
				return false;
			}
		}
		
		public function open(){
			if ($this->using==="new"){
				try {
					$this->conn=new PDO($this->conn_string, $this->user, $this->pass);
				} catch (PDOException $e){
					trigger_error("Could not connect to session database.",E_USER_ERROR);
				}
			}
			return true;
		}
		
		public function close(){
			if ($this->using==="new"){
				$this->conn=null;
			}
		}
		
		public function read($id){
			global $_SWDF;
			try {
				
				$query=$this->conn->prepare("SELECT data FROM ".$_SWDF['settings']['db']['tables']['sessions']." WHERE id=?");
				$query->execute(Array($id));
				$data=$query->fetchAll();
				if (isset($data[0]['data'])) {
					return $data[0]['data'];
				}
				
			} catch (PDOException $e) {
				trigger_error("Could not read from session database.",E_USER_ERROR);
			}
		}
		
		public function write($id,$data){
			global $_SWDF;
			try {
				$query=$this->conn->prepare("REPLACE INTO ".$_SWDF['settings']['db']['tables']['sessions']." (id,data,last_modified) VALUES (?,?,?);");
				return $query->execute(array($id,$data,date("Y-m-d H:i:s")));
			} catch (PDOException $e){
				trigger_error("Could not write to session database.",E_USER_ERROR);
			}			
		}	
		
		public function destroy($id){
			global $_SWDF;
			try {
				$query=$this->conn->prepare("DELETE FROM ".$_SWDF['settings']['db']['tables']['sessions']." WHERE id=?;");
				$query->execute(array($id));
			} catch (PDOException $e){
				trigger_error("Could not delete from session database.",E_USER_ERROR);
			}
		}

		public function clean($max_age){
			global $_SWDF;
			try {
				$max_time=time()-$max_age;
				$query=$this->conn->prepare("DELETE FROM ".$_SWDF['settings']['db']['tables']['sessions']." WHERE last_modified<=?;");
				return $query->execute(array(date("Y-m-d H:i:s",$max_time)));	
			} catch (PDOException $e){
				trigger_error("Could not delete from session database.",E_USER_ERROR);
			}			
		}
		
		public function __destruct(){
			if ($this->using==="passed"){
				//Save the session (session needs to close before $sdb object is destroyed)
				session_write_close();
			}
		}		
	}
?>