<?php

class Ruckusing_Logger {
  
  private $file = '';

  /**
   * Whether the logfile is known from previous ruckusing commands.
   *
   * @var bool|null
   */
  private $knownFile = null;

  public function __construct($file) {
    $this->file = $file;
    $this->knownFile = is_file($file);
    $this->fp = fopen($file, "a+");
    //register_shutdown_function(array("Logger", "close_log"));
  }
  
  public static function instance($logfile) {
    static $instance;
    if($instance !== NULL) {
      return $instance;
    }
    $instance = new Ruckusing_Logger($logfile);
    return $instance; 
  }
  
  public function log($msg) {
    if($this->fp) {
      $ts = date('M d H:i:s', time());
      $line = sprintf("%s [info] %s\n", $ts, $msg); 
      fwrite($this->fp, $line);
    } else {
      throw new Exception(sprintf("Error: logfile '%s' not open for writing!", $this->file));
    }
    
  }
  
  public function close() {
    if($this->fp) {
      fclose($this->fp);

      if (!$this->knownFile) {
          chmod($this->file, 0777);
      }
    }
  }
  
}//class()

?>