<?

  use Bitrix\Sale\PaySystem;

  class CloudpaymentHandler2
  {
    public static function OnAdminContextMenuShowHandler_button(&$items)
    {

      $two_stage_payment = false;

      if ($GLOBALS['APPLICATION']->GetCurPage() == '/bitrix/admin/sale_order_view.php' || $GLOBALS['APPLICATION']->GetCurPage() == '/bitrix/admin/sale_order_edit.php') {
        if (array_key_exists('ID', $_REQUEST) && $_REQUEST['ID'] > 0 && \Bitrix\Main\Loader::includeModule('sale')) {
          //$post = \Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getQueryList()->toArray();
          $order = \Bitrix\Sale\Order::load($_REQUEST['ID']);
          $propertyCollection = $order->getPropertyCollection();

          $two_stage_payment = true; ///двухстадийная оплата

          $TYPE = $order->isPaid() ? 'Y' : "N";

          $paymentCollection = $order->getPaymentCollection();
          foreach ($paymentCollection as $payment) {
            $psName = $payment->getPaymentSystemName(); // название платежной системы
            $psId = $payment->getPaymentSystemId();
            $ps = $payment->getPaySystem();
          }

          if ($ps):
            if ($TYPE == 'N' && $ps->getField("ACTION_FILE") == 'cloudpayment') {
              if ($psId) {
                $db_ptype = CSalePaySystemAction::GetList($arOrder = Array(), Array("ACTIVE" => "Y", "PAY_SYSTEM_ID" => $psId));
                while ($ptype = $db_ptype->Fetch()) {
                  $CLOUD_PARAMS = unserialize($ptype['PARAMS']);
                  if ($CLOUD_PARAMS['TYPE_SYSTEM']['VALUE'])
                    $two_stage_payment = $CLOUD_PARAMS['TYPE_SYSTEM']['VALUE'];
                }
              }

              global $DB;
              $PAY_VOUCHER_NUM = '';
              if ($_GET['ID']):
                $results = $DB->Query("select `PAY_VOUCHER_NUM` from `b_sale_order` where `ID`=" . $_GET['ID']);
                if ($row = $results->Fetch()) {
                  if ($row['PAY_VOUCHER_NUM']) {
                    $PAY_VOUCHER_NUM = $row['PAY_VOUCHER_NUM'];
                  }
                }
              endif;

              $FirstItem = array_shift($items);
              if (!$PAY_VOUCHER_NUM) {
                $items = array_merge(array($FirstItem), array(array(
                  'TEXT' => GetMessage("BUTTON_SEND_BILL"),
                  'LINK' => 'javascript:' . $GLOBALS['APPLICATION']->GetPopupLink(
                      array(
                        'URL' => "/bitrix/admin/cloudpayment_adminbutton.php?type=send_bill&ORDER_ID={$_GET['ID']}&LID=" . $order->getSiteId(),
                        'PARAMS' => array(
                          'width' => '400',
                          'height' => '40',
                          'resize' => false,
                          'resizable' => false,
                        )
                      )
                    ),
                  'WARNING' => 'Y',
                )
                ), $items);
              }

              /*
              if ($two_stage_payment && $PAY_VOUCHER_NUM)
              {
                              $FirstItem = array_shift($items);
                              $items = array_merge(array($FirstItem),array(array(
                                  'TEXT' => GetMessage("ACCEPT_PAY_TWO_STAGE"),
                                  'LINK' => 'javascript:'.$GLOBALS['APPLICATION']->GetPopupLink(
                                          array(
                                              'URL' => "/bitrix/admin/cloudpayment_adminbutton.php?type=pay_ok&ORDER_ID={$_GET['ID']}&LID=".$order->getSiteId(),
                                              'PARAMS' => array(
                                                  'width' => '400',
                                                  'height' => '120',
                                                  'resize' => false,
                                                  'resizable' => false,
                                              )
                                          )
                                      ),
                                  'WARNING' => 'Y',
                              )),$items);


              }  */

              foreach ($items as $Key => $arItem) {
                if ($arItem['LINK'] == '/bitrix/admin/sale_order_new.php?lang=ru&LID=s1') {
                  unset($items[$Key]);
                }
              }

            }     ///777
          endif;

        }

      }
    }

    function Object_to_array($data)
    {
      if (is_array($data) || is_object($data)) {
        $result = array();
        foreach ($data as $key => $value) {
          $result[$key] = self::Object_to_array($value);
        }
        return $result;
      }
      return $data;
    }

    public function OnCloudpaymentOrderDelete($ID)
    {
      CModule::IncludeModule("sale");
      if (empty($ID))
        return false;
      $arFilter = Array("ID" => $ID);
      $db_sales = CSaleOrder::GetList(array("DATE_INSERT" => "ASC"), $arFilter);
      if ($ar_sales = $db_sales->Fetch()) {

        $order = \Bitrix\Sale\Order::load($ID);
        $propertyCollection = $order->getPropertyCollection();
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
          $psName = $payment->getPaymentSystemName(); // название платежной системы
          $psId = $payment->getPaymentSystemId();
        }

        if (!isset($psId))
          return false;
        $CLOUD_PARAMS = self::get_module_value($psId);


        if (!empty($CLOUD_PARAMS['STATUS_AU']['VALUE']))
          $STATUS_AU = $CLOUD_PARAMS['STATUS_AU']['VALUE'];
        else $STATUS_AU = "AU";

        if ($ar_sales['STATUS_ID'] == $STATUS_AU) {
          $API_URL = 'https://api.cloudpayments.ru/payments/void';

          if ($psId) {
            if ($CLOUD_PARAMS['APIPASS']['VALUE'])
              $APIPASS = $CLOUD_PARAMS['APIPASS']['VALUE'];
            if ($CLOUD_PARAMS['APIKEY']['VALUE'])
              $APIKEY = $CLOUD_PARAMS['APIKEY']['VALUE'];
          }

          global $DB;
          $results = $DB->Query("select `PAY_VOUCHER_NUM` from `b_sale_order` where `ID`=" . $ID);
          if ($row = $results->Fetch()) {
            $ORDER_PRICE = $order->getPrice();
            if ($row['PAY_VOUCHER_NUM'] and $ORDER_PRICE > 0) {

              if ($curl = curl_init()) {

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $API_URL);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $APIPASS . ":" . $APIKEY);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "TransactionId=" . $row['PAY_VOUCHER_NUM']);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:39.0) Gecko/20100101 Firefox/39.0');
                $data = curl_exec($ch);
                curl_close($ch);

                $data1 = json_decode($data);

              }
            }
          }
        }
      }

    }

    function get_module_value($PS_ID)
    {
      if (!isset($PS_ID))
        return false;

      $db_ptype = CSalePaySystemAction::GetList($arOrder = Array(), Array("ACTIVE" => "Y", "PAY_SYSTEM_ID" => $PS_ID));
      while ($ptype = $db_ptype->Fetch()) {
        $CLOUD_PARAMS = unserialize($ptype['PARAMS']);
      }      

      return $CLOUD_PARAMS;
    }

    function void($payment, $refundableSum, $CLOUD_PARAMS)
    {
      $result = new PaySystem\ServiceResult();
      $error = '';
      $request = array(
        'TransactionId' => $payment->getField('PAY_VOUCHER_NUM'),
      );

      $url = 'https://api.cloudpayments.ru/payments/void';

      if ($CLOUD_PARAMS['APIPASS']['VALUE'])
        $accesskey = $CLOUD_PARAMS['APIPASS']['VALUE'];
      if ($CLOUD_PARAMS['APIKEY']['VALUE'])
        $access_psw = $CLOUD_PARAMS['APIKEY']['VALUE'];

      if ($accesskey && $access_psw) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $accesskey . ":" . $access_psw);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $out = self::Object_to_array(json_decode($content));
        if ($out['Success'] !== false) {
          $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
        } else {
          $error .= $out['Message'];
        }

        if ($error !== '') {
          $result->addError(new Error($error));
          PaySystem\ErrorLog::add(array(
            'ACTION' => 'returnPaymentRequest',
            'MESSAGE' => join("\n", $result->getErrorMessages())
          ));
        }
      }
    }

    function refund($payment, $refundableSum, $CLOUD_PARAMS)
    {
      $result = new PaySystem\ServiceResult();
      $error = '';
      $request = array(
        'TransactionId' => $payment->getField('PAY_VOUCHER_NUM'),
        'Amount' => number_format($refundableSum, 2, '.', ''),
        //  'JsonData'=>'',
      );

      $url = 'https://api.cloudpayments.ru/payments/refund';
      //     $url = 'https://noname.com/refund';

      if ($CLOUD_PARAMS['APIPASS']['VALUE'])
        $accesskey = $CLOUD_PARAMS['APIPASS']['VALUE'];
      if ($CLOUD_PARAMS['APIKEY']['VALUE'])
        $access_psw = $CLOUD_PARAMS['APIKEY']['VALUE'];

      if ($accesskey && $access_psw) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $accesskey . ":" . $access_psw);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $out = self::Object_to_array(json_decode($content));
        if ($out['Success'] !== false) {
          $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
        } else {
          $error .= $out['Message'];
        }

        if ($error !== '') {
          $result->addError(new Error($error));
          PaySystem\ErrorLog::add(array(
            'ACTION' => 'returnPaymentRequest',
            'MESSAGE' => join("\n", $result->getErrorMessages())
          ));
        }
      }
    }

    function get_transaction($tr_id, $CLOUD_PARAMS)
    {
      $url = 'https://api.cloudpayments.ru/payments/get';
      if ($CLOUD_PARAMS['APIPASS']['VALUE'])
        $accesskey = $CLOUD_PARAMS['APIPASS']['VALUE'];
      if ($CLOUD_PARAMS['APIKEY']['VALUE'])
        $access_psw = $CLOUD_PARAMS['APIKEY']['VALUE'];

      $request = array(
        'TransactionId' => $tr_id,
      );

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
      curl_setopt($ch, CURLOPT_USERPWD, $accesskey . ":" . $access_psw);
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
      $content = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);
      $out = self::Object_to_array(json_decode($content));

      return $out;
    }

    function OnCloudpaymentOnSaleBeforeCancelOrder($ORDER_ID, $STATUS_ID)
    {
      if (!empty($ORDER_ID)) {
        CModule::IncludeModule("sale");
        $order = \Bitrix\Sale\Order::load($ORDER_ID);
        $paymentCollection = $order->getPaymentCollection();
        $refundableSum = $order->getPrice();
        $tmp_ps = $order->getPaymentSystemId();
        if ($tmp_ps[0])
          $ps_id = $tmp_ps[0];
        if ($ps_id) {
          $CLOUD_PARAMS = self::get_module_value($ps_id);
          if (!empty($CLOUD_PARAMS['STATUS_CHANCEL']['VALUE']))
            $REFUND_STATUS = $CLOUD_PARAMS['STATUS_CHANCEL']['VALUE'];
          else $REFUND_STATUS = "RR";

          if (!empty($CLOUD_PARAMS['STATUS_AUTHORIZE']['VALUE']))
            $AUTHORIZE_STATUS = $CLOUD_PARAMS['STATUS_AUTHORIZE']['VALUE'];
          else $AUTHORIZE_STATUS = "CP";

          if (!empty($CLOUD_PARAMS['STATUS_VOID']['VALUE']))
            $VOID_STATUS = $CLOUD_PARAMS['STATUS_VOID']['VALUE'];
          else $VOID_STATUS = "AR";

          //возврат
          foreach ($paymentCollection as $payment) {
            if ($payment->getPaySystem()->getField("ACTION_FILE") == 'cloudpayment') {

              $transaction = self::get_transaction($payment->getField('PAY_VOUCHER_NUM'), $CLOUD_PARAMS);
              if ($transaction['Model']['Status'] == 'Authorized') {
                //Authorized
                self::void($payment, $refundableSum, $CLOUD_PARAMS);
              }
            }
            // else self::other_refund($payment,$refundableSum);
          }
        }
      }
    }

    function OnCloudpaymentStatusUpdate($ORDER_ID, $STATUS_ID)
    {
      if (!empty($ORDER_ID)) {
        CModule::IncludeModule("sale");
        $order = \Bitrix\Sale\Order::load($ORDER_ID);
        $paymentCollection = $order->getPaymentCollection();
        $refundableSum = $order->getPrice();
        $tmp_ps = $order->getPaymentSystemId();
        if ($tmp_ps[0])
          $ps_id = $tmp_ps[0];
        if ($ps_id) {
          $CLOUD_PARAMS = self::get_module_value($ps_id);
          if (!empty($CLOUD_PARAMS['STATUS_CHANCEL']['VALUE']))
            $REFUND_STATUS = $CLOUD_PARAMS['STATUS_CHANCEL']['VALUE'];
          else $REFUND_STATUS = "RR";

          if (!empty($CLOUD_PARAMS['STATUS_AUTHORIZE']['VALUE']))
            $AUTHORIZE_STATUS = $CLOUD_PARAMS['STATUS_AUTHORIZE']['VALUE'];
          else $AUTHORIZE_STATUS = "CP";

          if (!empty($CLOUD_PARAMS['STATUS_VOID']['VALUE']))
            $VOID_STATUS = $CLOUD_PARAMS['STATUS_VOID']['VALUE'];
          else $VOID_STATUS = "AR";

          switch ($STATUS_ID)     ///поменять на значение из модуля
          {
            case $REFUND_STATUS:
              //возврат
              foreach ($paymentCollection as $payment) {
                if ($payment->getPaySystem()->getField("ACTION_FILE") == 'cloudpayment') {

                  $transaction = self::get_transaction($payment->getField('PAY_VOUCHER_NUM'), $CLOUD_PARAMS);

                  if ($transaction['Model']['Status'] != 'Authorized') {
                    if ($payment->isPaid())
                      self::refund($payment, $refundableSum, $CLOUD_PARAMS);
                  }
                }
                // else self::other_refund($payment,$refundableSum);

              }

              break;

            case $VOID_STATUS:
              //возврат
              foreach ($paymentCollection as $payment) {
                if ($payment->getPaySystem()->getField("ACTION_FILE") == 'cloudpayment') {

                  $transaction = self::get_transaction($payment->getField('PAY_VOUCHER_NUM'), $CLOUD_PARAMS);
                  if ($transaction['Model']['Status'] == 'Authorized') {
                    //Authorized
                    self::void($payment, $refundableSum, $CLOUD_PARAMS);
                  }
                }
                // else self::other_refund($payment,$refundableSum);

              }

              break;

            case $AUTHORIZE_STATUS:

              $API_URL = 'https://api.cloudpayments.ru/payments/confirm';
              //
              //////
              ///
              if (empty($CLOUD_PARAMS['PAY_SYSTEM_ID_OUT']['VALUE'])):
                $CLOUD_PARAMS['PAY_SYSTEM_ID_OUT']['VALUE'] = 9;
              endif;

              self::Error7("________________confirm");

              foreach ($paymentCollection as $payment) {
                if ($payment->getPaySystem()->getField("ACTION_FILE") == 'cloudpayment') {
                  $ORDER_PRICE = $order->getPrice();

                  if ($payment->getField('PAY_VOUCHER_NUM') && $ORDER_PRICE) {
                    if ($curl = curl_init()) {
                      if ($CLOUD_PARAMS['APIPASS']['VALUE'])
                        $accesskey = $CLOUD_PARAMS['APIPASS']['VALUE'];
                      if ($CLOUD_PARAMS['APIKEY']['VALUE'])
                        $access_psw = $CLOUD_PARAMS['APIKEY']['VALUE'];

                        
                      $total = 0;
                      $POINTS = 0;



                        if ($payment->isPaid()):
                          $POINTS = $POINTS + $payment->getSum();
                        endif;


                      $basket = \Bitrix\Sale\Basket::loadItemsForOrder($order);
                      $basketItems = $basket->getBasketItems();

                      
                      $items = array();
                      $sum2 = 0;
                      foreach ($basketItems as $basketItem) {
                        $prD = \Bitrix\Catalog\ProductTable::getList(
                          array(
                            'filter' => array('ID' => $basketItem->getField('PRODUCT_ID')),
                            // 'select'=>array('VAT_ID'),
                          )
                        )->fetch();
                        if ($prD) {
                          if ($prD['VAT_ID'] == 0) {
                            $nds = NULL;
                          } else {
                            $nds = floatval($basketItem->getField('VAT_RATE')) == 0 ? 0 : $basketItem->getField('VAT_RATE') * 100;
                          }
                        } else {
                          $nds = NULL;
                        }

                        //    $ORDER_PRICE0 = $order->getPrice();
                        $basketQuantity = $basketItem->getQuantity();
                        $PRODUCT_PRICE = number_format($basketItem->getField('PRICE'), 2, ".", '');
                        $total = $total + ($basketItem->getField('PRICE') * $basketItem->getQuantity());
                          /*if ($CLOUD_PARAMS['PAYMENT_CURRENCY']['VALUE'] && $CLOUD_PARAMS['PAYMENT_CURRENCY']['VALUE'] != "RUB"):
                          $PRODUCT_PRICE = get_course($PRODUCT_PRICE, $CLOUD_PARAMS['PAYMENT_CURRENCY']['VALUE'], $CLOUD_PARAMS['COURSE_RATE']['VALUE']);
                          endif;*/

                          
                          //$sum2 = $sum2 + ($PRODUCT_PRICE*$basketQuantity);
                        $items[] = array(
                          'label' => $basketItem->getField('NAME'),
                          'price' => number_format($PRODUCT_PRICE, 2, ".", ''),
                          'quantity' => $basketQuantity,
                          'amount' => number_format($PRODUCT_PRICE * $basketQuantity, 2, ".", ''),
                          'vat' => $nds,
                        );


                      }

                      if ($order->getDeliveryPrice() > 0 && $order->getField("DELIVERY_ID")) {
                        if ($CLOUD_PARAMS['VAT_DELIVERY' . $order->getField("DELIVERY_ID")]['VALUE'])
                          $Delivery_vat = $CLOUD_PARAMS['VAT_DELIVERY' . $order->getField("DELIVERY_ID")]['VALUE'];
                        else $Delivery_vat = NULL;

                        $total = $total + $order->getDeliveryPrice();
                        $PRODUCT_PRICE_DELIVERY = number_format($order->getDeliveryPrice(), 2, ".", '');
                          /*if ($CLOUD_PARAMS['PAYMENT_CURRENCY']['VALUE'] && $CLOUD_PARAMS['PAYMENT_CURRENCY']['VALUE'] != "RUB"):
                          $PRODUCT_PRICE_DELIVERY = get_course($PRODUCT_PRICE_DELIVERY, $CLOUD_PARAMS['PAYMENT_CURRENCY']['VALUE'], $CLOUD_PARAMS['COURSE_RATE']['VALUE']);
                          endif;*/

                          // $sum2 = $sum2 + $PRODUCT_PRICE_DELIVERY;
                        $data = array();                      
                       
						            					
                        $data2 = array();						
						            
                        $items[] = array(
                          'label' => GetMessage('DELIVERY_TXT'),
                          'price' => number_format($PRODUCT_PRICE_DELIVERY, 2, ".", ''),
                          'quantity' => 1,
                          'amount' => number_format($PRODUCT_PRICE_DELIVERY, 2, ".", ''),
                          'vat' => $Delivery_vat,
                        ); 

                        unset($PRODUCT_PRICE_DELIVERY);
                        unset($PRODUCT_PRICE);
                      }


                      $Property = $order->getPropertyCollection()->getArray();

                      foreach ($Property['properties'] as $prop) {
                        if ($prop['IS_EMAIL'] == 'Y') {
                          $data2['EMAIL'] = current($prop['VALUE']);
                        }
                        if ($prop['IS_PHONE'] == 'Y') {
                          $data2['PHONE'] = current($prop['VALUE']);
                        }
                      }
                      // $TOTAL = $total;
                      // $data['POINTS'] = $POINTS;


                      $data['cloudPayments']['customerReceipt']['Items'] = $items;                     
                      $data['cloudPayments']['customerReceipt']['taxationSystem'] = intval($CLOUD_PARAMS['NALOG_TYPE']['VALUE']);
                      $data['cloudPayments']['customerReceipt']['email'] = $data2['EMAIL'];
                      $data['cloudPayments']['customerReceipt']['phone'] = $data2['PHONE'];						          
				                      
                      if ($CLOUD_PARAMS['POINTS']['VALUE'] == 'Y'):
                        $data['cloudPayments']['customerReceipt']["amounts"] = array(
                          "electronic" => number_format($total - $POINTS, 2, ".", ''),
                          "advancePayment" => 0,
                          "credit" => 0,
                          "provision" => number_format($POINTS, 2, ".", '')
                        );
                      else:
                        $data['cloudPayments']['customerReceipt']["amounts"] = array(
                          "electronic" => number_format($total - $POINTS, 2, ".", ''),
                          "advancePayment" => 0,
                          "credit" => 0,
                          "provision" => 0
                        );
                      endif;

                      $request = array(
                        'TransactionId' => $payment->getField('PAY_VOUCHER_NUM'),
                        'Amount' => number_format($ORDER_PRICE, 2, '.', ''),
                        'JsonData'=>json_encode($data),
                      );

                      self::Error7("CONFIRM___7");
                      self::Error7(print_r($request,1));                     


                      $ch = curl_init($API_URL);
                      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                      //curl_setopt($ch, CURLOPT_HTTPHEADER,array('Content-type: application/json'));
                      curl_setopt($ch, CURLOPT_USERPWD, $accesskey . ":" . $access_psw);
                      curl_setopt($ch, CURLOPT_URL, $API_URL);
                      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                      curl_setopt($ch, CURLOPT_POST, true);
                      curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                      $content = curl_exec($ch);
                      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                      $curlError = curl_error($ch);
                      curl_close($ch);
                      $out = self::Object_to_array(json_decode($content));

                      if ($out['Success'] !== false) {

                        /* global $DB;
                         $arFields=array(
                             "SUM_PAID"=>$ORDER_PRICE,
                             "PAYED"=>"Y",
                             "STATUS_ID"=>"P",
                         );

                         CSaleOrder::Update($ORDER_ID, $arFields);
                         unset($arFields);


                         $arFields=array(
                             "PAID"=>'"Y"'
                         );
                         $DB->Update("b_sale_order_payment", $arFields, "WHERE ORDER_ID=".intval($ORDER_ID), $err_mess.__LINE__);        ///���������� ������
                         unset($arFields);  */

                      }

                    }
                  }
                }

              }

              break;
          }
        }

      }
    }



    public function Error7($str)
    {
      $file = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/sale_payment/cloudpayment/log_NEW2019.txt';
      $current = file_get_contents($file);
      $current .= $str . "\n";
      file_put_contents($file, $current);
    }

  }

