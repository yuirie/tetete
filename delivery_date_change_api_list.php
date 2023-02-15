<?php
include_once("/home/webmaster/include/include.php");
include_once("/home/webmaster/class/smarty/SmartyWrapClass.php");
include_once("/home/webmaster/class/direct/sql.php");
include_once("/home/webmaster/class/delivery_date_change/DeliveryDateChangeApiClient.php");

// smarty変数初期化
$arraySmarty = [];
$requestData = [];

$objDirectSql = new DirectSql($requestData);
$objDeliveryDateChangeApiClient = new DeliveryDateChangeApiClient();

$table = 'delivery_date_change_api';
// テスト環境の場合と本番でテーブルから取得する値を変更
if($_ENV['DOCKER'] == 'true'){
	$selectsql = "select * from ".$table." where delete_flag=0 and is_real_account <> 1";
}else{
	$selectsql = "select * from ".$table." where delete_flag=0 and is_real_account = 1";
}
$resultApiUpdate = $objDirectSql->getDirectSqlData($selectsql);

foreach ($resultApiUpdate as $ApiUpdate){
	$delivery_date_change_api[$ApiUpdate['online_shop_main_id']] = $ApiUpdate;
}
$sql = "select * from online_shop_main where delete_flag=0";
$resultOnlineShopMain = $objDirectSql->getDirectSqlData($sql);
foreach ($resultOnlineShopMain as $OnlineShopMain){
	$arraySmarty['OnlineShopMain'][$OnlineShopMain['id']] = $OnlineShopMain['name'];
}

//直近のログの読み込み
$log_file = LogDir::DELIVERY_DATE_CHANGE.'delivery_date_change.log';
$success_log_file = LogDir::DELIVERY_DATE_CHANGE.'delivery_date_change_success.log';
$contents = file($log_file , FILE_IGNORE_NEW_LINES);
$succcess_contents = file($success_log_file, FILE_IGNORE_NEW_LINES);

//最後の1行を読み込む
$error= getMessage($contents);
$success= getMessage($succcess_contents);

//Yahoo!認可コード取得時
if(isset($_GET['code'])){
	$_POST['online_shop_main_id'] = 2;
}
//納期切り替え処理
if(isset($_POST['update']) || isset($_GET['code'])){

	$shop_name = $arraySmarty['OnlineShopMain'][$_POST['online_shop_main_id']];
	$result = $objDeliveryDateChangeApiClient->deliveryDateChange($_POST['online_shop_main_id'],$delivery_date_change_api[$_POST['online_shop_main_id']],$shop_name);

	//納期切り替えに成功したら納期切り替え更新日を更新
	if(!empty($result[0])){
		$objDirectSql = new DirectSql($requestData);
		$sql = "UPDATE ".$table." SET delivery_date_change_date = NOW(),update_date = NOW() WHERE id = ?";
		$objDirectSql->getDirectSqlData($sql,[$_POST['shop_id']]);
		$_SESSION['delivery_date_change_message_flg'] = 0;
	}else{
		$_SESSION['delivery_date_change_message_flg'] = 1;
	}
	if(!empty($result[1])){
		// result[1]に値が格納されていればエラーあり
		$_SESSION['delivery_date_change_error_flg'] = true;
	}else{
		$_SESSION['delivery_date_change_error_flg'] = false;
	}
	$_SESSION['delivery_date_change_online_shop_main_id'] = $_POST['online_shop_main_id'];
	header('Location: /manage/tool/delivery_date_change_api_list.php');
	exit;

//認証キー更新処理
}elseif(isset($_POST['shop_key_update'])){
	//認証キーが入力されていたら認証キーと認証キー更新日を更新
	if($_POST['new_shop_key'] !== ''){
		$sql = "UPDATE ".$table." SET shop_key = ?, key_update_date = NOW(), update_date = NOW() WHERE id = ?";
		$objDirectSql->getDirectSqlData($sql,[$_POST['new_shop_key'],$_POST['shop_id']]);
		$_SESSION['delivery_date_change_message_flg'] = 2;
	}
	header('Location: /manage/tool/delivery_date_change_api_list.php');
	exit;
}
$arraySmarty['message'] = ['納期切り替えが完了しました。','納期切り替え対象がありませんでした。','認証キーの更新が完了しました。'];
$arraySmarty['DeliveryDateList'] = $resultApiUpdate;
$arraySmarty['errorMessage'] = $error;
$arraySmarty['successMessage'] = $success;
$clsSmartyWrapClass	 = new SmartyWrapClass();
$clsSmartyWrapClass->AssignValue($arraySmarty);
$clsSmartyWrapClass->Display('./manage/tool/delivery_date_change_api_list.tpl');
unset($_SESSION['delivery_date_change_message_flg']);
unset($_SESSION['delivery_date_change_error_flg']);
unset($_SESSION['delivery_date_change_online_shop_main_id']);

//エラーメッセージ、成功メッセージを取得
function getMessage($contents){
	$index = count($contents) - 1;
	// 中身がないときは0に初期化
	if ( $index < 0) {
		$index = 0;
	}
	$pos = strrpos($contents[$index], '/');
	// /の後に出力ログがあるか確認
	if ($pos !== false) {
		$last_message = substr($contents[$index], $pos+1);
		if(!empty($last_message)){
			// /の後に出力ログがある場合はECCUBEのエラー
			$disp_message = json_decode($last_message,true);
		}else{
			// WOWMAと楽天,成功ログは/より前の出力を用いる
			$json = substr($contents[$index],19,-1);
			$disp_message = json_decode($json,true);
		}
	}
	return $disp_message;

}
