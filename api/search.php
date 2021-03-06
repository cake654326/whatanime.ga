<?php
ini_set("display_errors", 0);
require '../config.php';

if(isset($_GET['token'])) {

  $sql = mysqli_connect($sql_hostname, $sql_username, $sql_password, $sql_database);
  if (mysqli_connect_errno()) {
      header('HTTP/1.1 503 Service Unavailable');
      exit("Failed to connect to database");
}
else{
  mysqli_query($sql, "SET NAMES 'utf8'");

  if ($stmt = mysqli_prepare($sql, "SELECT `user_id`,`email`,`quota`,`quota_ttl` FROM `users` WHERE `api_key`=? LIMIT 0,1")){

    mysqli_stmt_bind_param($stmt, "s", $_GET['token']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    mysqli_stmt_bind_result($stmt, $user_id, $user_email, $quota, $quota_ttl);
    if( mysqli_stmt_num_rows($stmt) == 0) {
      header('HTTP/1.1 403 Forbidden');
      exit("Invalid API token");
    }
    else{

    }
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
  }
  if($user_id) {
    mysqli_query($sql, "UPDATE `users` SET `search_count`=`search_count`+1 WHERE `user_id`=".intval($user_id));
  }
}

mysqli_close($sql);
}
else{
  header("HTTP/1.1 401 Unauthorized");
  exit("Missing API token");
}

$uid = $user_id;

$url = 'https://www.google-analytics.com/collect';
$data = array(
  'v' => '1',
  't' => 'event',
  'tid' => 'UA-70950149-1',
  'cid' => $uid,
  'ec' => 'api',
  'ea' => 'search'
);

$options = array(
  'http' => array(
    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
    'method'  => 'POST',
    'content' => http_build_query($data)
  )
);
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

require '../vendor/autoload.php';

use Predis\Collection\Iterator;
Predis\Autoloader::register();
$redis = new Predis\Client('tcp://127.0.0.1:6379');
$redis_alive = true;
try {
    $redis->connect();
}
catch (Predis\Connection\ConnectionException $exception) {
    $redis_alive = false;
}

$lang = 'en';

if($redis->exists($uid)){
  $quota = intval($redis->get($uid));
  $expire = $redis->ttl($uid);
    if($uid > 1000 && $quota < 1){
      header("HTTP/1.1 429 Too Many Requests");
      exit("Search quota exceeded. Please wait ".$expire." seconds.");
    }
}
else{
  $redis->set($uid, $quota);
  $redis->expire($uid, $quota_ttl);
}


header('Content-Type: application/json');

if(isset($_POST['image'])){
    $quota--;
    $expire = $redis->ttl($uid);
    $redis->set($uid, $quota);
    $redis->expire($uid, $expire);

    $savePath = '/usr/share/nginx/html/pic/';
    $filename = microtime(true).'.jpg';
    $data = str_replace('data:image/jpeg;base64,', '', $_POST['image']);
    $data = str_replace(' ', '+', $data);
    
    // file_put_contents($savePath.$filename, base64_decode($data));
    $crop = true;
    if($crop){
      file_put_contents("../thumbnail/".$filename, base64_decode($data));
      exec("cd .. && python crop.py thumbnail/".$filename." ".$savePath.$filename);
      // exec("cd .. && python crop.py thumbnail/".$filename." thumbnail/".$filename.".jpg");
      unlink("../thumbnail/".$filename);
    }
    else{
      file_put_contents($savePath.$filename, base64_decode($data));
    }
    
    //extract image feature
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "http://192.168.2.11:8983/solr/anime_cl/lireq?field=cl_ha&extract=http://192.168.2.11/pic/".$filename);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    try{
        $res = curl_exec($curl);
        $extract_result = json_decode($res);
        $cl_hi = $extract_result->histogram;
        $cl_ha = $extract_result->hashes;
        $cl_hi_key = "cl_hi:".$cl_hi;
    }
    catch(Exception $e){

    }
    finally{
        curl_close($curl);
    }
    
    $final_result = new stdClass;
    $final_result->RawDocsCount = 0;
    $final_result->RawDocsSearchTime = 0;
    $final_result->ReRankSearchTime = 0;
    $final_result->CacheHit = false;
    $final_result->trial = 0;
    $final_result->docs = [];
    
    if(false && $redis->exists($cl_hi_key)){ //toggle caching here
        $final_result = json_decode($redis->get($cl_hi_key));
        $final_result->CacheHit = true;
    }
    else{
        $filter = "";
        if(isset($_POST['filter'])){
            $filter = $_POST['filter'] ? "fq=id:".intval($_POST['filter'])."/*" : "";
        }
        $trial = 0;
        while($trial < 3){
            $trial++;
            $final_result->trial = $trial;

            $nodes = array(
                "http://192.168.2.12:8983/solr/lire_0/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_1/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_2/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_3/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_4/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_5/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_6/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_7/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_8/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_9/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_10/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_11/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_12/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_13/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_14/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_15/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_16/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_17/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_18/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_19/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_20/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_21/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_22/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_23/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_24/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_25/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_26/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_27/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_28/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_29/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_30/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
                "http://192.168.2.12:8983/solr/lire_31/lireq?".$filter."&field=cl_ha&ms=false&url=http://192.168.2.11/pic/".$filename."&accuracy=".$trial."&candidates=1000000&rows=10",
            );
            $node_count = count($nodes);

            $curl_arr = array();
            $master = curl_multi_init();

            for($i = 0; $i < $node_count; $i++)
            {
                $url =$nodes[$i];
                $curl_arr[$i] = curl_init($url);
                curl_setopt($curl_arr[$i], CURLOPT_RETURNTRANSFER, true);
                curl_multi_add_handle($master, $curl_arr[$i]);
            }

            do {
                curl_multi_exec($master,$running);
            } while($running > 0);


            for($i = 0; $i < $node_count; $i++)
            {
                $results[] = curl_multi_getcontent($curl_arr[$i]);
            }

            foreach ($results as $res) {
                $result = json_decode($res);
                $final_result->RawDocsCount += intval($result->RawDocsCount);
                $final_result->RawDocsSearchTime += intval($result->RawDocsSearchTime);
                $final_result->ReRankSearchTime += intval($result->ReRankSearchTime);
                if(intval($result->RawDocsCount) > 0){
                  $final_result->docs = array_merge($final_result->docs,$result->response->docs);
                  usort($final_result->docs, "reRank");
                }
            }
            foreach($final_result->docs as $doc){
              if($doc->d <= 9) break 2; //break outer loop
            }
        }
        usort($final_result->docs, "reRank");
    }
    $final_result->docs = array_slice($final_result->docs, 0, 20);
    $final_result->quota = $quota;
    $final_result->expire = $expire;
    
    //combine adjacent time frames
    $docs = [];
    if(isset($final_result->RawDocsCount)){
        foreach($final_result->docs as $key => $doc){
            $path = explode('/',$doc->id)[0].'/'.explode('/',$doc->id)[1];
            $t = floatval(explode('/',$doc->id)[2]);
            $doc->from = $t;
            $doc->to = $t;
            $matches = 0;
            foreach($docs as $key2 => $doc2){
                if($doc->id == $doc2->id){ //remove duplicates
                    $matches = 1;
                    continue;
                }
                $path2 = explode('/',$doc2->id)[0].'/'.explode('/',$doc2->id)[1];
                $t2 = floatval(explode('/',$doc2->id)[2]);
                if($doc->id != $doc2->id && $path == $path2 && abs($t - $t2) < 2){
                    $matches++;
                    if($t < $doc2->from)
                        $docs[$key2]->from = $t;
                    if($t > $doc2->to)
                        $docs[$key2]->to = $t;
                }
            }
            if($matches == 0){
                $docs[] = $doc;
            }
        }
    }
    
    foreach($docs as $key => $doc) {
        #if($doc->d > 20){
        #    unset($docs[$key]);
        #    continue;
        #}
        $path = explode('/',$doc->id)[0].'/'.explode('/',$doc->id)[1];
        $t = floatval(explode('/',$doc->id)[2]);
        //$from = floatval(explode('?t=',$doc->id)[1]);
        //$to = floatval(explode('?t=',$doc->id)[1]);
        $start = round($doc->from - 16, 2);
        if($start < 0) $start = 0;
        $end = round($doc->to + 4, 2);
        
        $anilist_id = intval(explode('/',$path)[0]);
        $doc->anilist_id = $anilist_id;

        $file = explode('/',$path)[1];
        $episode = filename_to_episode($file);

        //$doc->i = $key;
        //$doc->start = $start;
        //$doc->end = $end;
        $doc->at = $t;
        $doc->season = ""; // deprecated
        $doc->anime = ""; // deprecated
        $doc->filename = $file;
        $doc->episode = $episode;
        $expires = time() + 300;
        #$doc->expires = $expires;
        $token = str_replace(array('+','/','='),array('-','_',''),base64_encode(md5('/'.$path.$start.$end.$secretSalt,true)));
        //$doc->token = $token;
        $tokenthumb = str_replace(array('+','/','='),array('-','_',''),base64_encode(md5($t.$secretSalt,true)));
        $doc->tokenthumb = $tokenthumb;
        $doc->similarity = 1 - ($doc->d/100);
        unset($doc->id);
        unset($doc->d);

        $doc->title = null;
        $doc->title_native = null;
        $doc->title_chinese = null;
        $doc->title_english = null;
        $doc->title_romaji = null;
        $doc->mal_id = null;
        $doc->synonyms = [];
        $doc->synonyms_chinese = [];

        // use anilist ID to get folder path
        $sql2 = mysqli_connect($sql_anime_hostname, $sql_anime_username, $sql_anime_password, $sql_anime_database);
        if (!mysqli_connect_errno()) {
            mysqli_query($sql2, "SET NAMES 'utf8'");
            if ($stmt = mysqli_prepare($sql2, "SELECT `season`,`title` FROM `anime` WHERE `anilist_id`=? LIMIT 0,1")){
                mysqli_stmt_bind_param($stmt, "i", $anilist_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                mysqli_stmt_bind_result($stmt, $season, $title);
                mysqli_stmt_fetch($stmt);

                if(mysqli_stmt_num_rows($stmt) > 0) {

                    $doc->season = $season;
                    $doc->anime = $title;
                    $doc->title = $title;

                    // use anilist ID to get titles of different languages
                    $request = array(
                    "size" => 1,
                    "_source" => array("idMal", "title", "synonyms", "synonyms_chinese", "isAdult"),
                    "query" => array(
                        "ids" => array(
                            "values" => array(intval($anilist_id))
                        )
                    )
                    );
                    $payload = json_encode($request);
                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, "http://127.0.0.1:9200/anilist/anime/_search");
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                    try{
                        $res = curl_exec($curl);
                        $result = json_decode($res);
                        if($result->hits && $result->hits->total > 0){
                            $doc->mal_id = intval($result->hits->hits[0]->_source->idMal);
                            $doc->title_romaji = $result->hits->hits[0]->_source->title->romaji ?? "";
                            $doc->title_native = $result->hits->hits[0]->_source->title->native ?? $doc->title_romaji;
                            $doc->title_english = $result->hits->hits[0]->_source->title->english ?? $doc->title_romaji;
                            $doc->title_chinese = $result->hits->hits[0]->_source->title->chinese ?? $doc->title_romaji;
                            $doc->title = $doc->title_native;
                            $doc->synonyms = $result->hits->hits[0]->_source->synonyms;
                            $doc->synonyms_chinese = $result->hits->hits[0]->_source->synonyms_chinese;
                            $doc->is_adult = $result->hits->hits[0]->_source->isAdult;
                        }
                    }
                    catch(Exception $e){

                    }
                    finally{
                        curl_close($curl);
                    }
                }
                mysqli_stmt_close($stmt);
            }
            mysqli_close($sql2);
        }
    }
    unset($final_result->docs);
    $final_result->docs = $docs;
    //unset($final_result->RawDocsCount);
    //unset($final_result->RawDocsSearchTime);
    //unset($final_result->ReRankSearchTime);
    unset($final_result->responseHeader);
    //$final_result->trial = $trial;
    //$final_result->accuracy = $accuracy;
    echo json_encode($final_result);
    //unlink($savePath.$filename);
}
else{
  echo "\"No data received, please send a POST request with form-data\"";
}

function reRank($a, $b){
    return ($a->d < $b->d) ? -1 : 1;
}

function filename_to_episode($filename){
    $filename = preg_replace('/\d{4,}/i','',$filename);
    $filename = str_replace("1920","",$filename);
    $filename = str_replace("1080","",$filename);
    $filename = str_replace("1280","",$filename);
    $filename = str_replace("720","",$filename);
    $filename = str_replace("576","",$filename);
    $filename = str_replace("960","",$filename);
    $filename = str_replace("480","",$filename);
    if(preg_match('/(?:OVA|OAD)/i',$filename))
        return "OVA/OAD";
    if(preg_match('/\W(?:Special|Preview|Prev)[\W_]/i',$filename))
        return "Special";
    if(preg_match('/\WSP\W{0,1}\d{1,2}/i',$filename))
        return "Special";
    $num = preg_replace('/.+?\[(\d+\.*\d+).{0,4}].+/i','$1',$filename);
    if($num != $filename)
        return floatval($num);
    $num = preg_replace('/.*(?:EP|第) *(\d+\.*\d+).+/i','$1',$filename);
    if($num != $filename)
        return floatval($num);
    $num = preg_replace('/^(\d+\.*\d+).{0,4}.+/i','$1',$filename); //start with %num
    if($num != $filename)
        return floatval($num);
    
    $num = preg_replace('/.+? - (\d+\.*\d+).{0,4}.+/i','$1',$filename); // - %num
    if($num != $filename)
        return floatval($num);
    
    $num = preg_replace('/.+? (\d+\.*\d+).{0,4} .+/i','$1',$filename); // %num 
    if($num != $filename)
        return floatval($num);
    
    $num = preg_replace('/.*?(\d+\.*\d+)\.mp4/i','$1',$filename);
    if($num != $filename)
        return floatval($num);
    
    $num = preg_replace('/.+? (\d+\.*\d+).{0,4}.+/i','$1',$filename);
    if($num != $filename)
        return floatval($num);
    return "";
}
?>
