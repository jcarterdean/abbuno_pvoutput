<?php
	$status = false;
	$gotinvData = false;
	/* Define inverter variables */
	$inverter_ip = ''; 		//inverter IP
	$inverter_uname = ''; 	//inverter username
	$inverter_pass = ''; 	//inverter password
	
	/* Define PvOutput variables */
	$pvout_sid = '';		//pvout System ID
	$pvout_apikey = '';		//pvout APIKey
	$donation = true; // set to false if your not premium
	
	/* debug flag */
	$debug = false;
	/*
		example cron to run every 5mins @ 1min interval past (e.g. 6mins/11mins
		1-59/5 * * * * php /home/pi/abbuno_pvoutput/GetABBData.php >> /home/pi/abbuno_pvoutput/ABBLog.log 2>&1
	
	/* define errors and warnings (work in progress)*/
	$eventCode = array(
		'W002'=>array(
			'type'=>'warning',
			'name'=>'Minimum Vin (1/2)',
			'desc'=>'Input UV'
		),
		'W032L'=>array(
			'type'=>'warning',
			'name'=>'Comms - Early Power-off',
			'desc'=>'Early Power-off'
		),
		'E012'=>array(
			'type'=>'error',
			'name'=>'5 B',
			'desc'=>'Internal error'
		),
		'E017'=>array(
			'type'=>'error',
			'name'=>'UNK',
			'desc'=>'Internal error'
		)
	);
	
	
	/* register handler for timeouts (inverter down etc) add array_key_first for php versions before 7*/
	
	if (!function_exists('array_key_first')) {
        function array_key_first(array $array){
            if (count($array)) {
                reset($array);
                return key($array);
            }
            return null;
        }
    }
	
	register_shutdown_function('shutdown');
	
	function shutdown() {
		global $status,$gotinvData;
		switch($status) {
			case (string)'PVO_OK':
				$msg = "Added Status to PVOUTPUT\n";
				break;
			case (string)'PVO_FAIL':
				$msg = "Failed to add Status to PVOUTPUT\n";
			default:
				if($gotinvData) {
					$msg = "Unknown error\n";
				} else {
					$msg = "Failed to Poll inverter.\n";
				}
				break;
		}
		echo "\n[".date('d/m/Y H:i').'] > '.$msg;

	}
	/* start */
	
	$auth = base64_encode($inverter_uname.':'.$inverter_pass);
	$ctx = stream_context_create(array('http'=>
		array(
			'timeout' => 5,
			'header' => array(
				'Authorization: Basic '.$auth
			)
		)
	));
	
	$data = file_get_contents("http://{$inverter_ip}/v1/livedata",true,$ctx);
	if($data) {
		$gotinvData = true;
		$data = json_decode($data,true);
		$parent = $data[array_key_first($data)];
		$dataModel = array();
		if(array_key_exists('points',$parent)) {
			foreach($parent['points'] as $val) {
					$dataModel[$val['name']] = $val['value'];
			}
			if($debug) { print_r($dataModel); }
			//send to pvoutput;
			sendtopvoutput($dataModel);
		}
	}
	
	
function sendtopvoutput($dataModel) {
	global $pvout_sid,$pvout_apikey,$donation,$debug,$status;
	//get previous 5min interval time
	$prev5Min = str_pad((floor(date('i')/5) * 5), 2, '0', STR_PAD_LEFT);
	$fields_string = '';
	$url = 'https://pvoutput.org/service/r2/addstatus.jsp';
	$fields = array(
		'd'=>date('Ymd'),
		't'=>date('H:').$prev5Min,
		'v1' => Round($dataModel['EDay_i'],2),
		'v2' => Round($dataModel['PacTogrid'],2),
		'v5' => Round($dataModel['TempInv'],2),
		'v6' => Round($dataModel['Vgrid'],2)
	);
	
	if($donation) {
		$fields = array_merge($fields,array(
			'v8' => Round($dataModel['Pin2'],2),//West Watts P2
			'v9' => Round($dataModel['Pin1'],2),//East Watts P1
			'v10' => Round($dataModel['PacTogrid']/$dataModel['Pin'],2),//Effeciancy
			'v11' => Round($dataModel['Vin2'],2),// West Volts P2
			'v12' => Round($dataModel['Vin1'],2)//East Volts P2
		));
	}
	if($dataModel['AlarmState'] > 0) {
			//attempt to add
			$fields['m1'] = 'In Alarm State';
	}
	if($debug) { print_r($fields); }
	
	foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
	rtrim($fields_string, '&');
	
	//not all php versions have curl, so alternative is to use file_get_contents and defining a post ctx stream;
	if(function_exists('curl_init')) {
		$ch = curl_init();
		//set the url, number of POST vars, POST data
		curl_setopt($ch, CURLOPT_URL, $url);
		//curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

		curl_setopt($ch, CURLOPT_POST, count($fields));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'X-Pvoutput-SystemId: '.$pvout_sid,
			'X-Pvoutput-Apikey: '.$pvout_apikey,
			
		));
		curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
		$result = curl_exec($ch);
		curl_close($ch);
	} else {
		$ctx = stream_context_create(array('http'=>
			array(
				'timeout' => 30,
				'method' => 'POST',
				'header' => array(
					'Content-Type: application/x-www-form-urlencoded',
					'X-Pvoutput-SystemId: '.$pvout_sid,
					'X-Pvoutput-Apikey: '.$pvout_apikey
				),
				'content'=>$fields_string
			)
		));
		$result = file_get_contents($url,true,$ctx);
	}
	if($result == 'OK 200: Added Status') {
		$status = 'PVO_OK';
	} else {
		$status = 'PVO_FAIL';
	}
	if($debug) { print_r($result); }
}
/* data model 
Array
(
    [CountryStd] => 75
    [InputMode] => 0
    [WRtg] => 4040
    [Iin1] => 2.7396836280823
    [Vin1] => 168.94033813477
    [Pin1] => 450.85690307617
    [Iin2] => 4.7076120376587
    [Vin2] => 170.05120849609
    [Pin2] => 784.16510009766
    [Pin] => 1235.0219726562
    [Igrid] => 4.8997583389282
    [Pgrid] => 1201.0810546875
    [Vgrid] => 244.48387145996
    [Fgrid] => 50.105461120605
    [Ppeak] => 1432.6646728516
    [cosPhi] => 1
    [SplitPhase] => 0
    [VgridL1_N] => 122.62254333496
    [VgridL2_N] => 121.84661102295
    [PacTogrid] => 1201.0810546875
    [Fan1rpm] => 0
    [Temp1] => 41.107357025146
    [TempInv] => 46.561645507812
    [TempBst] => 45.015914916992
    [Riso] => 20
    [IleakInv] => 4868.341796875
    [IleakDC] => 4921.193359375
    [Vgnd] => 264.37338256836
    [SysTime] => 640430208
    [EDay_i] => 1725
    [EWeek_i] => 7840
    [EMonth_i] => 7840
    [EYear_i] => 7840
    [ETotal] => 7986
    [E0_7D] => 1825
    [E0_30D] => 1825
    [GlobState] => 6
    [AlarmState] => 0
    [DC1State] => 2
    [DC2State] => 2
    [InvState] => 2
    [WarningFlags] => 0
    [PACDeratingFlags] => 0
    [QACDeratingFlags] => 0
    [SACDeratingFlags] => 0
    [ClockState] => 0
)

*/

?>