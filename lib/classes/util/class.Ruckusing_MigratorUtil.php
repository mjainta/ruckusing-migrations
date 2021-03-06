<?php

class Ruckusing_MigratorUtil {
 
	/**
	 * @var Ruckusing_BaseAdapter|Ruckusing_MySQLAdapter
	 */
  private $adapter = null;
  private $migrations = array();
  
  function __construct($adapter) {
    $this->adapter = $adapter;
  }
  
  /*
    Return the max version number from the DB, or "0" in the case of no versions available.
    We must use strings because our date/timestamp when treated as an integer would cause overflow.
  */
  public function get_max_version() {
    // We only want one row but we cannot assume that we are using MySQL and use a LIMIT statement
    // as it is not part of the SQL standard. Thus we have to select all rows and use PHP to return
    // the record we need
    $versions_nested = $this->adapter->select_all(sprintf("SELECT version FROM %s", RUCKUSING_TS_SCHEMA_TBL_NAME));
    $versions = array();
    foreach($versions_nested as $v) {
      $versions[] = $v['version'];
    }
    $num_versions = count($versions);
    if($num_versions) {
      sort($versions); //sorts lowest-to-highest (ascending)
      return (string)$versions[$num_versions-1];
    } else {
      return null;
    }
  }

  /* 
    This methods calculates the actual set of migrations that should be performed, taking into account
    the current version, the target version and the direction (up/down). When going up this method will 
    skip migrations that have not been executed, when going down this method will only include migrations 
    that have been executed.
  */
	public function get_runnable_migrations($direction, $destination = null, $use_cache = true, $templates = null) {
	  // cache migration lookups and early return if we've seen this requested set
	  if($use_cache == true) {
      $key = $direction . '-' . $destination;
      if(array_key_exists($key, $this->migrations)) {
        return($this->migrations[$key]);
      }
    }
	  
		$runnable = array();
		$migrations = array();
		
		if($templates === null)
		{
			$templates = $this->adapter->getTemplates();
		}
		
		$migrations = $this->get_migration_files($templates, $direction);
		$current = $this->find_version($migrations, $this->get_max_version() );
		$target = $this->find_version($migrations, $destination);
		if(is_null($target) && !is_null($destination) && $destination > 0) {
		  trigger_error("Could not find target version {$destination} in set of migrations.");
	  }
	  //If direction is 'up', start is at 0, otherwise at the return of array_search
	  $start = $direction == 'up' ? 0 : array_search($current, $migrations);
	  //If start is false, set start to 0
	  $start = $start !== false ? $start : 0;
	  //finish is the return of array_search for the target version
	  $finish = array_search($target, $migrations);
	  
	  if($direction === 'up')
	  {
		  //If direction is up and finish was set to false, set it to the last element
		  $finish = $finish !== false ? $finish : (count($migrations) - 1);
	  }
	  elseif($direction === 'down')
	  {
		  //If direction is down and finish was set to false, set it to -1
		  $finish = $finish !== false ? $finish : -1;
	  }
	  
	  $item_length = $finish - $start; //The length of how many items should be sliced
	  
	  if($item_length < 0)
	  {
		//If the item length is negative, we start at the migration which should be last executed and go for the absolute number of item_length
		$runnable = array_slice($migrations, ($start - abs($item_length)) + 1, abs($item_length));
	  }
	  else
	  {
		/* 
		 * If the item length is positive or 0, we start at the current version (or 0) and go on for (item_length + 1) migrations. 
		 * The +1 is necessary because we start at 0 or the current migration.
		 */
		$runnable = array_slice($migrations, $start, $item_length + 1);
	  }
	  
    $executed = $this->get_executed_migrations();
	//var_dump($runnable, $executed);
    $to_execute = array();

    foreach($runnable as $migration) {
      //Skip ones that we have already executed
      if($direction == 'up' && in_array($migration['version'], $executed)) {
        continue;
      }
      //Skip ones that we never executed
      if($direction == 'down' && !in_array($migration['version'], $executed)) {
        continue;
      } 
      $to_execute[] = $migration;
    }
    if($use_cache == true) {
      $this->migrations[$key] = $to_execute;
    }
    return($to_execute);
	}//get_relevant_files
	    
  /* 
    Generate a timestamp for the current time in UTC format
    Returns a string like '20090122193325'
  */
  public static function generate_timestamp() {
    return gmdate('YmdHis', time()); 
  }
  
  /* If we are going UP then log this version as executed, if going DOWN then delete
	this version from our set of executed migrations.
	*/
	public function resolve_current_version($version, $direction, $template) {
	  if($direction === 'up') {
	    $this->adapter->set_current_version($version, $template);
    }
    if($direction === 'down') {
	    $this->adapter->remove_version($version);
    }
    return $version;
  }

  /* 
    Returns an array of strings which represent version numbers that we *have* migrated
  */
  public function get_executed_migrations() {
    return $this->executed_migrations();
  }	
    
  /*
		Return a set of migration files, according to the given direction.
		If nested, then return a complex array with the migration parts broken up into parts
		which make analysis much easier.
	*/
	public static function get_migration_files($templates, $direction) { 
	    $files = array();
		
		foreach ($templates as $template)
		{
			$migrationDir = RUCKUSING_MIGRATION_DIR.'/'.$template;
			
			if(!is_dir($migrationDir)) {
				die("\nRuckusing_MigratorUtil - ({$migrationDir}) is not a directory.\n");
			}
			
			$filesInDir = scandir($migrationDir);
			
			foreach ($filesInDir as $file)
			{
				$files[] = $template.'/'.$file;
			}
		}
		
		$valid_files = array();
		
  	$file_cnt = count($files);
  	if($file_cnt > 0) {
  		for($i = 0; $i < $file_cnt; $i++) {
  			if(preg_match('/^(.+)\/(\d+)_(.*)\.php$/', $files[$i], $matches)) {
  				if(count($matches) == 4) {
  				  $valid_files[] = $files[$i];
  				}//if-matches
        }//if-preg-match
  		}//for
  	}//if-file-cnt		
  	
    if($direction == 'down') {
      $valid_files = array_reverse($valid_files);
    }
	
		//user wants a nested structure
		$files = array();
		$cnt = count($valid_files);
		for($i = 0; $i < $cnt; $i++) {
			$migration = $valid_files[$i];
			if(preg_match('/^(.+)\/((\d+)_(.*)\.php)$/', $migration, $matches)) {
				$files[] = array(
										'version' => $matches[3],
										'class' 	=> $matches[4],
										'template'		=> $matches[1],
										'path'		=> $matches[0],
										'file'	=>	$matches[2]
									);					
			}
		}//for
		
		sort($files);
		return $files;
  }//get_migration_files
  
  //== Private methods  
  
  /* Find the specified structure (representing a migration) that matches the given version */
	private function find_version($migrations, $version) {
    $len = count($migrations);
    for($i = 0; $i < $len; $i++) {
      if($migrations[$i]['version'] == $version) {
        return $migrations[$i];
      }
    }
    return null;
  }
  
  /* Find the index of the migration in the set of migrations that match the given version */
  private function find_version_index($migrations, $version) {
    //edge case
    if(is_null($version)) {
      return null;
    }
    $len = count($migrations);
    for($i = 0; $i < $len; $i++) {
      if($migrations[$i]['version'] == $version) {
        return $i;
      }
    }
    return null;
  }

  /*
	Query the database and return a list of migration versions that *have* been executed
	*/
	private function executed_migrations() {
	  $query_sql = sprintf('SELECT version FROM %s', RUCKUSING_TS_SCHEMA_TBL_NAME);
	  $versions = $this->adapter->select_all($query_sql);
	  $executed = array();
	  foreach($versions as $v) {
	    $executed[] = $v['version'];
    }
    sort($executed);
    return $executed;
  }


}

?>