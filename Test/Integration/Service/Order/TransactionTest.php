<?php
/**
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Test\Integration\Service\Order;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\Encryptor;
use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Payment\Service\Order\Transaction;
use Mollie\Payment\Test\Integration\IntegrationTestCase;

class TransactionTest extends IntegrationTestCase
{
    /**
     * @magentoConfigFixture current_store payment/mollie_general/use_custom_redirect_url 0
     */
    public function testRedirectUrlWithoutCustomUrl()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);

        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setId(9999);

        $result = $instance->getRedirectUrl($order, 'paymenttoken');

        $this->assertStringContainsString('mollie/checkout/process', $result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/use_custom_redirect_url 1
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url
     */
    public function testRedirectUrlWithEmptyCustomUrl()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);

        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setId(9999);

        $result = $instance->getRedirectUrl($order, 'paymenttoken');

        $this->assertStringContainsString('mollie/checkout/process', $result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/use_custom_redirect_url 1
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url https://www.mollie.com
     */
    public function testRedirectUrlWithFilledCustomUrl()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);

        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setId(9999);

        $result = $instance->getRedirectUrl($order, 'paymenttoken');

        $this->assertStringContainsString('https://www.mollie.com', $result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/use_custom_redirect_url 1
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url https://www.mollie.com/?order_id={{ORDER_ID}}&payment_token={{PAYMENT_TOKEN}}&increment_id={{INCREMENT_ID}}&short_base_url={{base_url}}&unsecure_base_url={{unsecure_base_url}}&secure_base_url={{secure_base_url}}
     */
    public function testAppendsTheParamsToTheUrl()
    {
        $configMock = $this->createMock(ScopeConfigInterface::class);

        $configMock
            ->method('getValue')
            ->withConsecutive(
                ['web/unsecure/base_url', 'store', null],
                ['web/unsecure/base_url', 'store', null],
                ['web/secure/base_url', 'store', null]
            )
            ->willReturnOnConsecutiveCalls(
                'http://base_url.test/',
                'http://unsecure_base_url.test/',
                'https://secure_base_url.test/'
            );

        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class, [
            'scopeConfig' => $configMock,
        ]);

        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setId(9999);
        $order->setIncrementId(8888);

        $result = $instance->getRedirectUrl($order, 'paymenttoken');

        $this->assertStringContainsString('order_id=9999', $result);
        $this->assertStringContainsString('increment_id=8888', $result);
        $this->assertStringContainsString('payment_token=paymenttoken', $result);
        $this->assertStringContainsString('short_base_url=http://base_url.test/', $result);
        $this->assertStringContainsString('unsecure_base_url=http://unsecure_base_url.test/', $result);
        $this->assertStringContainsString('secure_base_url=https://secure_base_url.test/', $result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/use_custom_redirect_url 1
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url https://www.mollie.com/?order_id={{order_id}}&payment_token={{payment_token}}&increment_id={{increment_id}}&short_base_url={{base_url}}&unsecure_base_url={{unsecure_base_url}}&secure_base_url={{secure_base_url}}
     */
    public function testTheVariablesAreCaseInsensitive()
    {
       $this->testAppendsTheParamsToTheUrl();
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/use_custom_redirect_url 1
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url_parameters hashed_parameters
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url_hash dummyhashfortest
     * @magentoConfigFixture current_store payment/mollie_general/custom_redirect_url https://www.mollie.com/?hash={{ORDER_HASH}}
     */
    public function testHashesTheOrderId()
    {
        /** @var Encryptor $encryptor */
        $encryptor = $this->objectManager->get(Encryptor::class);

        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);

        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setId(9999);

        $result = $instance->getRedirectUrl($order, 'paymenttoken');

        $query = parse_url($result, PHP_URL_QUERY);
        parse_str($query, $parts);
        $hash = base64_decode($parts['hash']);

        $this->assertEquals(9999, $encryptor->decrypt($hash));
    }

    public function testGeneratesTheCorrectRedirectUrlWhenMultishipping()
    {
        $orders = [
            $this->objectManager->create(OrderInterface::class)->setId(777),
            $this->objectManager->create(OrderInterface::class)->setId(888),
            $this->objectManager->create(OrderInterface::class)->setId(999),
        ];

        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);
        $result = $instance->getMultishippingRedirectUrl($orders, 'PAYMENT_TOKEN_TEST');

        $this->assertStringContainsString('order_ids[0]=777', urldecode($result));
        $this->assertStringContainsString('order_ids[1]=888', urldecode($result));
        $this->assertStringContainsString('order_ids[2]=999', urldecode($result));
        $this->assertStringContainsString('payment_token=PAYMENT_TOKEN_TEST', urldecode($result));
        $this->assertStringContainsString('utm_nooverride=1', urldecode($result));
    }

    public function testThrowsAnExceptionWhenTheOrderListIsEmpty()
    {
        $orders = [];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The provided order array is empty');

        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);
        $instance->getMultishippingRedirectUrl($orders, 'PAYMENT_TOKEN_TEST');
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/type live
     * @magentoConfigFixture current_store payment/mollie_general/use_webhooks enabled
     */
    public function testReturnsTheDefaultWebhookUrl()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);
        $result = $instance->getWebhookUrl();

        $this->assertStringContainsString('mollie/checkout/webhook/', $result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/type live
     * @magentoConfigFixture current_store payment/mollie_general/use_webhooks disabled
     */
    public function testIgnoresTheDisabledFlagWhenInLiveMode()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);
        $result = $instance->getWebhookUrl();

        $this->assertStringContainsString('mollie/checkout/webhook/', $result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/type test
     * @magentoConfigFixture current_store payment/mollie_general/use_webhooks disabled
     * @magentoConfigFixture current_store payment/mollie_general/custom_webhook_url custom_url_for_test
     */
    public function testReturnsNothingWhenDisabledAndInTestMode()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);
        $result = $instance->getWebhookUrl();

        $this->assertEmpty($result);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/type test
     * @magentoConfigFixture current_store payment/mollie_general/use_webhooks custom_url
     * @magentoConfigFixture current_store payment/mollie_general/custom_webhook_url custom_url_for_test
     */
    public function testReturnsTheCustomWebhookUrl()
    {
        /** @var Transaction $instance */
        $instance = $this->objectManager->create(Transaction::class);
        $result = $instance->getWebhookUrl();

        $this->assertEquals('custom_url_for_test', $result);
    }
}
