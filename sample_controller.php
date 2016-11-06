<?php
/**
* @author Shikhar kumar (shikhar.kr@gmail.com)
* Controller to import products through feed
*/

use Tygh\Registry;
use Tygh\Mailer;
use Tygh\Http;

if (!defined('BOOTSTRAP')) { die('Access denied'); }  

/*******************************************************************************
* config variables
* Should be from a config files 
*******************************************************************************/

$tshirt_default_cat_id = 152306 ; 

$tshirt_cats = array(
    'Mens' => 152307,
    'Womens' => 152308,
    'Boys' => 152309,
    'Girls' => 152310,
	);
$tshirt_company_id = SUPERHERO_COMPANY_ID ;

/*******************************************************************************
* Public controller paths
*******************************************************************************/

/**
* Reads feed and creates products in the database
* @param $fr - index to start, optional
* @param $lt - limit, optional
*/
if ($mode == 'create_products') {
	
	$fr = 0 ;
	$lt = 10 ;
	if($_REQUEST['from']){
		$fr = (int)$_REQUEST['from'] ;
	}
	if($_REQUEST['limit']){
		$lt = $_REQUEST['limit'] ;
	}

    $obj = simplexml_load_file('https://www.wearyourbeer.com/fm-feeds/mrbabu.xml');
	$i = 0 ;
	while ($i < $lt && $obj->Product[$fr]->Productcode) {
        set_time_limit(0);
		$c = (string)$obj->Product[$fr]->Department ;
		$opv = (string)$obj->Product[$fr]->Option ;
		$cid = $tshirt_cats[$c] ? $tshirt_cats[$c] : $tshirt_default_cat_id ;
		$code = (string)$obj->Product[$fr]->Productcode ;
		$cost = (float)$obj->Product[$fr]->Price ;
		$appwt = 1 ;
		$wto = (int)$obj->Product[0]->Weight ;  // ounce
		if(is_numeric($wto)){
			$appwt = ceil($wto * 0.0625) ;
		}
		list($p, $calc_log) = fn_calculate_price($cost,$appwt);	
		foreach($calc_log as $k => $v){
            	$cstr .= $k . ' ' . $v . '|' ;    
        	}
		$d = array(
			'company_id' => $tshirt_company_id,
			'product' => (string)$obj->Product[$fr]->Title,
	        'category_ids' => array($cid),
	        'main_category' => $cid,
	        'price' => $p,
	        //'list_price' =>
	        'amount' => (int)$obj->Product[$fr]->Stock,
	        'product_code' => $code,
	        'status' => 'A',
	        'full_description' => (string)$obj->Product[$fr]->Description,
	        'product_features' => array (
	                PRODUCT_FEATURE_IMPORTED_ID => 'Y',
	                PRODUCT_FEATURE_DELIVERY_ID => 'Delivery: 7-14 working days',
	                PRODUCT_FEATURE_LOG_ID => $cstr,
	                ),
	        'main_pair' => array(
	            'detailed'=>array(
	                'image_path'=> (string)$obj->Product[$fr]->Image
	            )	
	           )

			) ;
        
        // addtnl img
        $aimg = array();
        if($obj->Product[$fr]->Image2){
            $aimg[] = array(
                'detailed' => array(
                    'image_path'=> (string)$obj->Product[$fr]->Image2
                )
            );
        }        
        if($obj->Product[$fr]->Image3){
            $aimg[] = array(
                'detailed' => array(
                    'image_path'=> (string)$obj->Product[$fr]->Image3
                )
            );
        }
        $d['image_pairs'] = $aimg ;

		if($opv){
			$pid = db_get_field('SELECT product_id FROM size_options WHERE product_code = ?s AND size = ?s',$code,$opv);
		} else {
			$pid = db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s',$code);
		}
        // create
		list($id,$msg) = fn_api_update_product($d, $pid) ;

		if($id && $opv){
			$r = db_get_row("SELECT * FROM size_options WHERE product_id = ?i", $id);
			$u = array(
				'product_code'=>$code,
				'size'=>$opv,
				'product_id'=>$id
				);
			if($r){
				$u['updated_at'] = time();
				db_query('UPDATE size_options SET ?u WHERE product_id= ?i', $u, $id);
			}else{
				$u['created_at'] = time();
				db_query('INSERT INTO size_options ?e', $u) ;
			}
			
		}

		echo $fr. ' '.$code.' '. $id . ' ' . $msg . '<br/>' ;
		
		if(empty($id)){
			echo 'Error creating product <br/>'.print_r($d) ;
		}

		$fr += 1 ;
		$i += 1 ;
	}

	exit('Complete') ;
}

/**
* Reads feed and updates products in the database
* To be used in Crontab
* @param $fr - index to start, optional
* @param $lt - limit, optional
*/
if ($mode == 'update_stock') {
	
	$fr = 0 ;
	$lt = 10 ;
	if($_REQUEST['from']){
		$fr = (int)$_REQUEST['from'] ;
	}
	if($_REQUEST['limit']){
		$lt = $_REQUEST['limit'] ;
	}

    $obj = simplexml_load_file('https://www.wearyourbeer.com/fm-feeds/mrbabu.xml');
	$i = 0 ;
	while ($i < $lt && $obj->Product[$fr]->Productcode) {
        set_time_limit(0);
		$opv = (string)$obj->Product[$fr]->Option ;
		$code = (string)$obj->Product[$fr]->Productcode ;
		$amt = (int)$obj->Product[$fr]->Stock ;
		$pid = null ;

		if($opv){
			$pid = db_get_field('SELECT product_id FROM size_options WHERE product_code = ?s AND size = ?s ',$code,$opv);
		}else{
			$pid = db_get_field('SELECT product_id FROM ?:products WHERE product_code = ?s', $code);
		}

		if($pid){
			db_query('UPDATE ?:products SET amount = ?i WHERE product_id = ?i', $amt,$pid);
			echo $fr. ' '.$code.' '. $pid . '/'  ;
		}else {
			echo $fr. ' '.$code.' '. 'NA' . '/'  ;
		}
		
		$fr += 1 ;
		$i += 1 ;
	}

	exit('Complete') ;	
}

/*******************************************************************************
* Auxillary controller paths
*******************************************************************************/
/**
* Show all the categories,weight,options 
*/
if ($mode == 'show_cats') {

	$obj = simplexml_load_file('https://www.wearyourbeer.com/fm-feeds/mrbabu.xml');
	$ca = array();
	$wa = array();
	$oa = array();

	foreach ($obj->Product as $v) {
		$c = (string)$v->Department ;
		if($c && !in_array($c, $ca)){
			$ca[] = $c ;
		}

		$w = (string)$v->Weight ;
		if($w && !in_array($w, $wa)){
			$wa[] = $w ;
		}

		$o = (string)$v->Option ;
		if($o && !in_array($o, $oa)){
			$oa[] = $o ;
		}
	}
	echo '<pre>' ;
	print_r($ca) ;	
	print_r($wa) ;	
	print_r($oa) ;
	echo '</pre>' ;
	exit ;
}
/**
* Show products with no options
*/
if ($mode == 'no_options') {
	$obj = simplexml_load_file('https://www.wearyourbeer.com/fm-feeds/mrbabu.xml');
	$i = 0 ;
	echo '<pre>';

	foreach ($obj->Product as $v) {
		$o = (string)$v->Option ;
		if(!$o){
			print_r($v);
			$i += 1 ;
		}
	}

	echo 'Count :'.$i ;
	exit ;

}

/*******************************************************************************
* Test controller paths 
* should be a diff file with more test cases
*******************************************************************************/

/**
* Test price calculation
*/
if ($mode == 'test_price') {
	echo '<pre>' ;
    
    list($p,$l) = fn_calculate_price(29.99, 1) ;  
    
    echo assert($p == 54.6)?'T1 Passed':'T1 failed' ;
    echo '<br/>';
    
    list($p,$l) = fn_calculate_price(55, 3) ;  
    echo assert($p == 98.7)?'T2 Passed':'T2 failed' ;
    echo '<br/>' ;
	
	exit('Tests completed');
}

/*******************************************************************************
* Core Functions 
* should be a diff file with more test cases
*******************************************************************************/

/**
* Calculates the selling price
* @param $cost - in dollars
* @param $appwt - wight in pounds
* @return array(price, calc_log)
*/
function fn_calculate_price($cost = 0, $appwt = 1)
{
	
    $calc_log = array() ;
    $p = 0 ; // price
    
    if($cost > 0){
        $p = $cost ;
        $calc_log['cost'] = $cost ;
    }		

    $p += 5 ;
    $calc_log['s1'] = 5 ;
    $s2rate = 5.5 ; //10 ; // shipping
    
    if($appwt < 5){
        $s2rate = 5.5 ;
    } else if ($appwt >= 5 && $appwt < 15) {
        $s2rate = 5 ;
    } else if ($appwt >= 15 && $appwt < 20) {
        $s2rate = 5 ;
    } else {
        $s2rate = 5 ;
    }
    $s2 = $appwt * $s2rate ;
    $p += $s2 ;
    $calc_log['s2rate'] = $s2rate ;
    $calc_log['appwt'] = $appwt ;
    $calc_log['s2'] = $s2 ;

    $margin = _get_price_margin_differential($cost);  // margin

    $p += $margin ;
    $calc_log['margin'] = $margin ;     
    
    $p += 2 ;
    $calc_log['tax'] = 2 ;

    $dpad = 2 ;  // default duty pad
    if($cost >= 0 && $cost < 200 ){
        $dpad = 2 ;
    } else if ($cost >= 200 && $cost < 400){
        $dpad = 4 ;
    } else if ($cost >= 400 && $cost < 1000){
        $dpad = 6 ;
    } else {
        $dpad = 12 ;
    } 
    $p += $dpad ;
    $calc_log['dpad'] = $dpad ;   
    
    $duty = round(($cost+5)*0.06 ,2);   // duty
    $p += $duty ;
    $calc_log['duty'] = $duty ;
    
    $convoffset = round ( $p * CONVERSION_OFFSET / 100, 2) ;
    $p += $convoffset ;
    $calc_log['cnv'] = $convoffset ;

    $calc_log['price'] = $p ;
    
    $p = round($p/3.5, 1, PHP_ROUND_HALF_UP) * 3.5 ;
    $calc_log['rp'] = $p ;
    array_walk($calc_log, function(&$v,&$k){$v = (string)$v;}) ;

    return array(
    	$p,
    	$calc_log
    	);

}
/**
* Creates/updates a product
* @param $d product details in associative array
* @param $pid product id, if not passed, new product will be created
* @return array(id, msg)
*/
// update/create product through api
function fn_api_update_product($d, $pid=null)
{
    if(empty($d)){
        return array(false, 'Empty array'); 
    }

    if (empty($d['product']) || empty($d['category_ids']) || empty($d['main_category']) || empty($d['price'])|| empty($d['company_id']) ) {
        return array(false, 'Values missing')  ;
    }

    if(empty($d['status'])){
        $d['status'] = 'A' ;
    }

    // create
    $extra['basic_auth'] = array ( API_LOGIN, API_KEY) ; 
    $extra['headers'][] = 'Content-Type: application/json' ;

    if($pid){
    	$res = Http::put(fn_url('','C').'/api/products/'.$pid, json_encode($d), $extra) ;
    }else{
		$res = Http::post(fn_url('','C').'/api/products/', json_encode($d), $extra) ;
    }
    
    $r = json_decode($res, true); 

    if(json_last_error() === JSON_ERROR_NONE) {
        return array($r['product_id'],'Success');
    } else {
        return array(false,'Error creating') ;
    }
    
}
