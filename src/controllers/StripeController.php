<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\stripe\controllers;

use skeeks\cms\shop\models\ShopBill;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\models\ShopPaySystem;
use skeeks\cms\shop\stripe\StripePaysystemHandler;
use Stripe\Event;
use Stripe\PaymentIntent;
use yii\base\Exception;
use yii\helpers\Json;
use yii\web\Controller;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class StripeController extends Controller
{

    /**
     * @var bool
     */
    public $enableCsrfValidation = false;


    public function actionCallback()
    {
        \Yii::info("--------------- Callback ---------------------", static::class);
        /**
         * @var $shopPaySystem ShopPaySystem
         */
        $pay_system_id = \Yii::$app->request->get("pay_system_id");

        \Yii::info("PaySystem id={$pay_system_id}", static::class);

        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        //\Yii::info("payload: ".$payload, static::class);

        //\Yii::info("sig header: " . $sig_header, static::class);

        $shopPaySystem = ShopPaySystem::find()->cmsSite()->andWhere(['id' => $pay_system_id])->one();
        if (!$shopPaySystem) {
            $error = "Платежная система (id = {$pay_system_id}) не найдена";
            \Yii::error($error, static::class);
            throw new Exception($error);
        }
        \Yii::info("Paysystem: ".$shopPaySystem->name, static::class);

        /**
         * @var $stripe StripePaysystemHandler
         */
        $stripe = $shopPaySystem->handler;
        if (!$stripe || !$stripe instanceof StripePaysystemHandler) {
            $error = "Не задан stripe обработчик платежной системы";
            \Yii::error($error, static::class);
            throw new Exception($error);
        }
        \Yii::info("Stripe success", static::class);

        try {
            $event = Event::constructFrom(Json::decode($payload));
        } catch (\Exception $e) {
            \Yii::error($e->getMessage(), static::class);
        }


        /*try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, "whsec_582e9d6f09dd22889b254ae42776efb7e38c8b7da70b1d641ee2b67c444c70a7"
            );
            \Yii::info("Event created", static::class);
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            $error = "Invalid payload: " . $e->getMessage();
            \Yii::error($error, static::class);
            throw new Exception($error);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            $error = "Invalid signature " . $e->getMessage();
            \Yii::error($error, static::class);
            //throw new Exception($error);
        }*/

        \Yii::info("Event: ".$event->type, static::class);
        if ($event->type == 'payment_intent.succeeded') {
            $paymentIntent = $event->data->object;

            /**
             * @var $paymentIntent PaymentIntent
             * @var $shopBill ShopBill
             */
            $paymentIntent->id;


            \Yii::info("case payment_intent.succeeded", static::class);
            \Yii::info("{$paymentIntent->id}", static::class);

            $shopBill = ShopBill::find()->andWhere(['external_id' => $paymentIntent->id])->one();
            if (!$shopBill) {
                $error_message = "Счет не найден: {$paymentIntent->id}";
                \Yii::error($error_message, static::class);
                throw new Exception($error_message);
            }


            if (($shopBill->amount * 100) != $paymentIntent->amount) {
                $error_message = "Суммы не совпадают в счете: {$paymentIntent->id}";
                \Yii::error($error_message, static::class);
                throw new Exception($error_message);
            }

            $transaction = \Yii::$app->db->beginTransaction();

            try {

                $payment = new ShopPayment();
                $payment->cms_user_id = $shopBill->cms_user_id;
                $payment->shop_pay_system_id = $shopBill->shop_pay_system_id;
                $payment->shop_order_id = $shopBill->shop_order_id;
                $payment->amount = $shopBill->amount;
                $payment->currency_code = $shopBill->currency_code;
                $payment->comment = "Оплата по счету №{$shopBill->id} от ".\Yii::$app->formatter->asDate($shopBill->created_at);
                $payment->external_data = $data;

                if (!$payment->save()) {
                    throw new Exception("Не сохранился платеж: ".print_r($payment->errors, true));
                }

                $shopBill->isNotifyUpdate = false;
                $shopBill->paid_at = time();
                $shopBill->shop_payment_id = $payment->id;

                if (!$shopBill->save()) {
                    throw new Exception("Не обновился счет: ".print_r($shopBill->errors, true));
                }

                $shopBill->shopOrder->paid_at = time();
                $shopBill->shopOrder->save();


                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollBack();
                \Yii::error($e->getMessage(), static::class);
            }

        }

        return 'OK';
    }


    public function actionFail()
    {
        \Yii::warning("Tinkoff fail: ".print_r(\Yii::$app->request->get(), true), static::class);

        /**
         * @var $bill ShopBill
         */
        if (!$orderId = \Yii::$app->request->get('OrderId')) {
            throw new Exception('Bill not found');
        }

        if (!$bill = ShopBill::find()->where(['id' => $orderId])->one()) {
            throw new Exception('Bill not found');
        }

        print_r(\Yii::$app->request->get());
        die;

        return $this->redirect($shopOrder->getPublicUrl(\Yii::$app->request->get()));
    }

    public function actionNotify()
    {
        \Yii::info("actionNotify", static::class);

        $json = file_get_contents('php://input');
        \Yii::info("JSON: ".$json, static::class);

        try {

            if (!$json) {
                throw new Exception('От банка не пришли данные json.');
            }

            $data = Json::decode($json);

            if (!isset($data['OrderId']) && !$data['OrderId']) {
                throw new Exception('Некорректны запрос от банка нет order id.');
            }

            /**
             * @var $shopBill ShopBill
             */
            if (!$shopBill = ShopBill::findOne($data['OrderId'])) {
                throw new Exception('Заказ не найден в базе.');
            }

            if ($shopBill->id != $data['OrderId']) {
                throw new Exception('Не совпадает номер заказа.');
            }

            $amount = $shopBill->money->amount * $shopBill->money->currency->subUnit;
            if ($amount != $data['Amount']) {
                throw new Exception('Не совпадает сумма заказа.');
            }

            if ($data['Status'] == "REFUNDED") {
                //todo:Доделать возврат
            }

            if ($data['Status'] == "REJECTED") {
                \Yii::info("Неуспешный платеж", static::class);

                $shopBill->closed_at = time();
                //$json
                //$shopBill->external_data = $shopBill->external_data;

                if (!$shopBill->save()) {
                    throw new Exception("Не обновился счет: ".print_r($shopBill->errors, true));
                }
            }

            if ($data['Status'] == "CONFIRMED") {
                \Yii::info("Успешный платеж", static::class);

                $transaction = \Yii::$app->db->beginTransaction();

                try {

                    $payment = new ShopPayment();
                    $payment->cms_user_id = $shopBill->cms_user_id;
                    $payment->shop_pay_system_id = $shopBill->shop_pay_system_id;
                    $payment->shop_order_id = $shopBill->shop_order_id;
                    $payment->amount = $shopBill->amount;
                    $payment->currency_code = $shopBill->currency_code;
                    $payment->comment = "Оплата по счету №{$shopBill->id} от ".\Yii::$app->formatter->asDate($shopBill->created_at);
                    $payment->external_data = $data;

                    if (!$payment->save()) {
                        throw new Exception("Не сохранился платеж: ".print_r($payment->errors, true));
                    }

                    $shopBill->isNotifyUpdate = false;
                    $shopBill->paid_at = time();
                    $shopBill->shop_payment_id = $payment->id;

                    if (!$shopBill->save()) {
                        throw new Exception("Не обновился счет: ".print_r($shopBill->errors, true));
                    }

                    $shopBill->shopOrder->paid_at = time();
                    $shopBill->shopOrder->save();


                    $transaction->commit();
                } catch (\Exception $e) {
                    $transaction->rollBack();
                    \Yii::error($e->getMessage(), static::class);
                    return $e->getMessage();
                }

            }

        } catch (\Exception $e) {
            \Yii::error($e->getMessage(), static::class);
            return $e->getMessage();
        }

        $this->layout = false;
        return "OK";
    }

}