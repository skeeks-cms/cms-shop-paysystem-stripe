<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\stripe;

use skeeks\cms\shop\models\ShopOrder;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\paysystem\PaysystemHandler;
use skeeks\yii2\form\fields\FieldSet;
use yii\base\Exception;
use yii\helpers\ArrayHelper;

/**
 * @see https://stripe.com/docs/testing
 *
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class StripePaysystemHandler extends PaysystemHandler
{
    /**
     * @var
     */
    public $api_key;


    /**
     * @return array
     */
    static public function descriptorConfig()
    {
        return array_merge(parent::descriptorConfig(), [
            'name' => "Stripe",
        ]);
    }


    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['api_key'], 'required'],
            [['api_key'], 'string'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'api_key' => "Ключ api",
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'api_key' => "Необходимо получить в личном кабинете https://stripe.com/",
        ]);
    }


    /**
     * @return array
     */
    public function getConfigFormFields()
    {
        return [
            'main' => [
                'class'  => FieldSet::class,
                'name'   => 'Основные',
                'fields' => [
                    'api_key',

                ],
            ],

        ];
    }

    /**
     * @param ShopPayment $shopPayment
     * @return \yii\console\Response|\yii\web\Response
     */
    public function actionPayOrder(ShopOrder $shopOrder)
    {
        $model = $this->getShopBill($shopOrder);

        $paysystem = $model->shopPaySystem->handler;
        $price = (float)$model->money->amount;

        $successUrl = $shopOrder->getUrl(['success_paied' => true], true);
        $failUrl = $shopOrder->getUrl(['fail_paied' => true], true);

        $stripe = new \Stripe\StripeClient(
            $this->api_key
        );
        $payData = [
            'success_url' => $successUrl,
            'cancel_url'  => $failUrl,
            'line_items'  => [
                [
                    'price_data' => [
                        'currency'            => 'eur',
                        'unit_amount_decimal' => $shopOrder->money->amount * 100,
                        'product_data'        => [
                            'name' => 'Order №'.$shopOrder->id,
                        ],
                    ],
                    'quantity'   => 1,
                ],
            ],
            'mode'        => 'payment',
        ];

        if ($shopOrder->contact_email) {
            $payData['customer_email'] = $shopOrder->contact_email;
        }

        \Yii::info("request: ".print_r($payData, true), self::class);

        try {
            $result = $stripe->checkout->sessions->create($payData);
        } catch (\Exception $exception) {
            \Yii::error(print_r($exception->getMessage(), true), self::class);
            throw $exception;
        }

        \Yii::info("response: ".print_r($result, true), self::class);


        $model->external_id = $result->payment_intent;
        $model->external_data = $result->toArray();

        if (!$model->save()) {
            throw new Exception("Не удалось сохранить платеж: ".print_r($model->errors, true));
        }

        return \Yii::$app->response->redirect($result->url);
    }
}