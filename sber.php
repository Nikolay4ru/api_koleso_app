<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

print_r("Sberbank Payment Integration Example\n");

$vars = array();
 
$vars['userName'] = 'koleso-russia';
$vars['password'] = '4/4oa2BuN5CnQw&to=}M';
 
/* ID заказа в магазине */
$vars['orderNumber'] = '312343123323';
 
/* Корзина для чека (необязательно) */
$cart = array(
	array(
		'positionId' => 1,
		'name' => 'Название товара',
		'quantity' => array(
			'value' => 1,    
			'measure' => 'шт'
		),
		'itemAmount' => 1 * (1000 * 100),
		'itemCode' => '123456',
		'tax' => array(
			'taxType' => 0,
			'taxSum' => 0
		),
		'itemPrice' => 1000 * 100,
	)
);
 
$vars['orderBundle'] = json_encode(
	array(
		'cartItems' => array(
			'items' => $cart
		)
	), 
	JSON_UNESCAPED_UNICODE
);
 
/* Сумма заказа в копейках */
$vars['amount'] = 1000 * 100;
 
/* URL куда клиент вернется в случае успешной оплаты */
$vars['returnUrl'] = 'http://koleso.app/success/';
	
/* URL куда клиент вернется в случае ошибки */
$vars['failUrl'] = 'http://koleso.app/error/';
$order_id = "423423459-03432";
/* Описание заказа, не более 24 символов, запрещены % + \r \n */
$vars['description'] = 'Заказ №' . $order_id . ' на koleso.app';
 
$ch = curl_init('https://securepayments.sberbank.ru/payment/rest/register.do?' . http_build_query($vars));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HEADER, false);
$res = curl_exec($ch);
curl_close($ch);

var_dump($res);
$res = json_decode($res, true);
if (isset($res['errorCode']) && $res['errorCode'] == 0) {
    // Успешно
    echo "Payment URL: " . $res['formUrl'] . "\n";
} else {
    // Ошибка
    echo "Error: " . $res['errorMessage'] . "\n";
}


?>