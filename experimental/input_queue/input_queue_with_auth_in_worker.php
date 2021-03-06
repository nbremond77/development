<?php

  define('EMONCMS_EXEC', 1);
  
  $fp = fopen("runlock", "w");
  if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

  chdir("/var/www/latest");

  require "process_settings.php";
  $mysqli = new mysqli($server,$username,$password,$database);
  
  $redis = new Redis();
  $redis->connect("127.0.0.1");

  require("Modules/user/user_model.php");
  $user = new User($mysqli,$redis,null);
  
  require "Modules/feed/feed_model.php";
  $feed = new Feed($mysqli,$redis,$timestore_adminkey);

  require "Modules/input/input_model.php";
  $input = new Input($mysqli,$redis,$feed);

  require "Modules/input/process_model.php";
  $process = new Process($mysqli,$input,$feed);


  $rn = 0;
  $ltime = time();
  $usleep = 100000;
  
  while(true)
  {
    if ((time()-$ltime)>=1)
    {
      $ltime = time();

      $buflength = $redis->llen('buffer');
      
      // A basic throthler to stop the script using up cpu when there is nothing to do.
      
      // Fine tune sleep
      if ($buflength<2) {
        $usleep += 50;
      } else {
        $usleep -= 50;
      }
      
      // if there is a big buffer reduce sleep to zero to clear buffer.
      if ($buflength>100) $usleep = 0;
      
      // if throughput is low then increase sleep significantly
      if ($rn==0) $usleep = 100000;
      
      // sleep cant be less than zero
      if ($usleep<0) $usleep = 0;
      
      echo "Buffer length: ".$buflength." ".$usleep." ".$rn."\n";
      
      $rn = 0;
    }
    
    // check if there is an item in the queue to process
    $line_str = false;
    
    if ($redis->llen('buffer')>0)
    {    
      // check if there is an item in the queue to process
      $line_str = $redis->lpop('buffer');
    }
    
    if ($line_str)
    {
      $rn ++;
      
      $req = json_decode($line_str);
      $session = $user->apikey_session($req->apikey);
      
      // The first and second value in the csv is userid, time and nodeid
      $userid = $session['userid'];
      $time = $req->time;
      $nodeid = $req->nodeid;
      
      $line_parts = explode(',',$req->csv);
      
      // Load current user input meta data
      // It would be good to avoid repeated calls to this
      $dbinputs = $input->get_inputs($userid);
      
      $tmp = array();
      
      // For each node input
      $name = 1;
      for ($i=0; $i<count($line_parts); $i++)
      {
        
        $value = $line_parts[$i];
        if (!isset($dbinputs[$nodeid][$name])) {
          $inputid = $input->create_input($userid, $nodeid, $name);
          $dbinputs[$nodeid][$name] = true;
          $dbinputs[$nodeid][$name] = array('id'=>$inputid);
          $input->set_timevalue($dbinputs[$nodeid][$name]['id'],$time,$value);
        } else {
          $inputid = $dbinputs[$nodeid][$name]['id'];
          $input->set_timevalue($dbinputs[$nodeid][$name]['id'],$time,$value);
          
          if ($dbinputs[$nodeid][$name]['processList']) $tmp[] = array('value'=>$value,'processList'=>$dbinputs[$nodeid][$name]['processList']);
        }

        $name++;
      }
      
      foreach ($tmp as $i) $process->input($time,$i['value'],$i['processList']);
    }
      
    usleep($usleep);
  }
