<?php

  /*
   * Rob Reed (ljunkie)
   *
   * Scrape TMDB for missing cast thumb image and update PMS DB
   *
   *  Created: 2013
   * Modified: 2013-01-17
   *
   * VERSION: 1.0
   *
   */


## The Movie Database API Key : http://www.themoviedb.org/account 
define("TMDB_API_KEY", 'PUT_YOUR_API_KEY_HERE'); 

## PATH to your PMS db (linux example below)
define("PMS_DB", '/var/lib/plexmediaserver/Library/Application Support/Plex Media Server/Plug-in Support/Databases/com.plexapp.plugins.library.db');



/************************ NO NEED TO EDIT BELOW ****************************/

// quick and dirty error checks
if ( (!defined("TMDB_API_KEY")) or (strlen(TMDB_API_KEY) < 5) or TMDB_API_KEY == "PUT_YOUR_API_KEY_HERE") {
  print "\n\tError: You must use an API Key\n";
  print "\t* Edit " . basename(__FILE__) . " -- and add your TMDB API Key to TMDB_API_KEY\n\n";
  print "\n\tTo register for an API key, head into your account page: http://www.themoviedb.org/account \n".
    "\t on The Movie Database and generate a new key from within the \"API\" section.\n";
  print "\n";
  exit;
}
if ( (!defined("PMS_DB")) ) {
  print "\n\tError: You must specify your PMS db path\n";
  exit;
}
if  (!file_exists(PMS_DB)) {
  print "\n\tError: Your PMS db doesn't exist? \n";
  print " \nFile not found: \"" . PMS_DB . "\"\n\n";
  exit;
}
// end quick and dirty error checks

define("TMDB_API_URL", 'http://api.themoviedb.org/3');
define("ONLY_ACTORS",false); // if for some reason you only want to check for actors.. usually not wanted or supported

$longopts  = array("all","debug",);
$options = getopt("ad",$longopts);

 // set this if you cron the script ( will only update thumbs that have been updated in the past couple days )
if ( isset($options['a']) or isset($options['all'])   ) {    $all = true; } else {   $all = false; }
if ( isset($options['d']) or isset($options['debug']) ) {  $debug = true; } else {   $debug = false; }
define("DEBUG",$debug); // will do everything except for modify the DB

if (VerifyAPIkey()) {
  ProcessThumbs($all); // yep - thats it :)
}

/*********************************************************************************************************/

function WriteThumbs($thumbs) {
  if (is_array($thumbs)) {
    print "\n  Processing " .  sizeof($thumbs) . " thumbs! [ DB updates imminent ]\n";
    if (DEBUG) {       print "\n\t*** DRY RUN *** NO DB Updates!\n\n";    }
    $dir = 'sqlite:' . PMS_DB;
    $dbh  = new PDO($dir) or die("cannot open the database");
    $query = 'update tags set user_thumb_url = ? where id = ? and tag_type = ?';
    $sth = $dbh->prepare($query);
    
    foreach ($thumbs as $name => $values) {
      $image = $thumbs[$name]['image'];
      if (isset($image) && !empty($image)) {
	printf("\tProcssing %-30s %-30s\n",$name,$image);
	if (!DEBUG) {
	  foreach ($values as $tag_type => $tag_id) {
	    //print "update tags set user_thumb_url = $image where id = $tag_id and tag_type = $tag_type\n";
	    $sth->execute(array($image, $tag_id, $tag_type));
	  }
	}
      }
    }
    print "\n";
    $dbh = null;
  }
}

function ProcessThumbs($all) {

  print "\n\nProcessing missing images for actors/directors/writer/producers... \n";
  if (DEBUG) {       print "\n\t*** DRY RUN *** NO DB Updates!\n\n";    }
  $rate_req = 30; 
  $rate_sec = 15;
  
  // Get missing thumbs
  $dir = 'sqlite:' . PMS_DB;
  $dbh  = new PDO($dir) or die("cannot open the database");
  $query = 'SELECT * from tags where tag_type in (4,5,6,7) and user_thumb_url = "" ';
  if (!$all) {    $query .= ' and ( updated_at >  date("now","-1 days")  or created_at >  date("now","-1 days") ) ';  }
  $query .= ' order by tag';

  // $query = 'SELECT * from tags where tag_type in (4,5,6,7)'; if someone wants to overwrite them all!
  $count = 0;
  $thumbs = array();
  foreach ($dbh->query($query) as $row)  {
    $name = trim($row['tag']);
    $name = preg_replace('/\s+/', ' ',$name);
    $name = str_replace('"', "", $name);
    $name = str_replace("'", "", $name);
    $name = strtolower($name);
    $name = iconv('utf8', 'ascii//TRANSLIT', $name);
    if (!empty($name)){
      $thumbs[$name][$row['tag_type']] = $row['id'];
    }
    $count++;
  }
  $dbh = null;
  
  //  iterate though thumbs and query TMDB for them
  $total_wanted = sizeof($thumbs);
  print "\nTotal Thumbs missing: $count \n";
  print " Total unique people: $total_wanted\n\n";
  if (!$all) { print "  ** limited to the last day ** \n\n";}
  $found = array();  

  
  if (ONLY_ACTORS) {
    $newThumbs = array();
    foreach ($thumbs as $key => $tags) {
      if (isset($tags[6])) {
	$newThumbs[$key] = $thumbs[$key];
      }
    }
    $thumbs = $newThumbs;
    $total_wanted = sizeof($thumbs);
    print "\nTotal Actor Thumbs missing: $count \n";
    print " Total unique people: $total_wanted\n\n";
    $found = array();  
  }
  
  $proc = 0;
  $proc_rt = 0;
  $time = time();
  $write_at = 10; # write every 10 found images
  printf("%-10s %-10s %-30s %s\n", "Processed", "Percent", "Person", "Status") ;
  print "----------------------------------------------------------------------\n";
  
  
  
  foreach ($thumbs as $key => $tags) {

    $proc++;
    $proc_rt++;
    $taken = time()-$time;
    
    if (sizeof($found) >= $write_at) {
      WriteThumbs($found);
      $found = array();
      $time = $time+(time()-$time); // increment the timer
    }

    if ($proc_rt >= $rate_req) {
      $wait = $time+$rate_sec-time();
      //$taken = time()-$time;
      if ($wait > 0) {
	print "\n ** queried $proc_rt in $taken seconds -- TMDB Rate Limit Exceeded ($rate_req every $rate_sec seconds) -- waiting $wait before processing...\n\n";
	sleep($wait);
      }
      $time = time(); //reset timer
      $proc_rt = 0;
    } 
    
    $search = $key;
    $percent = sprintf("%.2f%%",$proc/$total_wanted*100);
    printf("%-10d %-10s %-30s", $proc, $percent, $search) ;
    if ($image = getActorThumb($search)) {
      print $image;
      $found[$key] = $thumbs[$key];
      $found[$key]['image'] = $image;
    } else {
      //print " *** NOT FOUND ***";
    }
    print "\n";
  }
  
  // Final write out
  WriteThumbs($found);
}



function getActorThumb($actor) {
  $adult="true"; // adult actors? why not
  $json =  queryAPI('/search/person?query='.urlencode($actor).'&include_adult='.$adult); 
  // first match -- yea, we want it
  if ( isset($json->{'results'}[0]->{'profile_path'}) and !empty($json->{'results'}[0]->{'profile_path'}) ) {
    return getImageBase($json->{'results'}[0]->{'profile_path'});
    
  } 
  // we will take the second match too :)
  else if ( isset($json->{'results'}[1]->{'profile_path'}) and !empty($json->{'results'}[1]->{'profile_path'}) ) {
    print " * second result * ";
    return getImageBase($json->{'results'}[1]->{'profile_path'});
  } 
  // we will grab an image from a lower result if the name is an exact match ( should we limit more? )
  else {
    if (isset($json->{'results'}) && is_array($json->{'results'})) {
      // $info = "";
      foreach ($json->{'results'} as $match => $values) {
	if (!empty($json->{'results'}[$match]->{'profile_path'})) {
	  if ($actor == $json->{'results'}[$match]->{'name'}) {
	    $item = $match+1;
	    print " *** name match (result #$item) *** ";
	    return getImageBase($json->{'results'}[$match]->{'profile_path'});
	    /*
	      $info .= "\n\t$match: " . $actor."=".$json->{'results'}[$match]->{'name'} ." :: " 
	      . getImageBase($json->{'results'}[$match]->{'profile_path'}) . " :: " 
	      . $json->{'results'}[0]->{'popularity'};
	    */
	    break;
	  }
	}
      }
      print " *** NOT FOUND ***";
      /*
	if (!empty($info)) {
	print "\n\n";
	print $info;
	print "\n\n";
      }
      */
    }
  }
}


function queryAPI($query) {
  $s = preg_match("/\?/",$query) ? "&" : "?";
  $url = TMDB_API_URL . $query . $s . 'api_key=' . TMDB_API_KEY;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HEADER, FALSE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json"));
  $response = curl_exec($ch);
  curl_close($ch);
  if (isJson($response)) {
    return json_decode($response);
  } else {
    print "Error: result from $url -> not JSON\n";
  }
}

function getImageBase($image = null) {
  global $base_image; // allow one to cache the base image 
                      // no need to multiple API calls if someone tries to use getImageBase() multiple times
  if (!isset($base_image)) { 
    $json =  queryAPI("/configuration"); 
    $base_image = $json->{'images'}->{'base_url'} . 'original/';
  }
  if (empty($image)) {
    return $base_image;
  }else {
    return preg_replace("/([^:])\/\//","$1/",$base_image . $image);
  }
}


function verifyAPIkey() {
  /* quick function to either return valid or exit if a return code exists */
  $json =  queryAPI("/configuration"); 
  if (isset($json->{'status_code'})) {
    print "\n status_message: " . $json->{'status_message'} . " - ";
    print "status_code:" . $json->{'status_code'} . "\n\n";
    exit;
  }
  return 1;
}

function isJson($string) {
  json_decode($string);
  return (json_last_error() == JSON_ERROR_NONE);
}
