<?php

namespace Pagarme\Core\Kernel\Services;

use Exception;
use MundiAPILib\APIException;
use MundiAPILib\Configuration;
use MundiAPILib\Controllers\ChargesController;
use MundiAPILib\Controllers\CustomersController;
use MundiAPILib\Controllers\OrdersController;
use MundiAPILib\Exceptions\ErrorException;
use MundiAPILib\Models\CreateCancelChargeRequest;
use MundiAPILib\Models\CreateCaptureChargeRequest;
use MundiAPILib\Models\CreateOrderRequest;
use MundiAPILib\MundiAPIClient;
use Pagarme\Core\Kernel\Abstractions\AbstractEntity;
use Pagarme\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Pagarme\Core\Kernel\Aggregates\Charge;
use Pagarme\Core\Kernel\Exceptions\InvalidParamException;
use Pagarme\Core\Kernel\Exceptions\NotFoundException;
use Pagarme\Core\Kernel\Factories\OrderFactory;
use Pagarme\Core\Kernel\ValueObjects\Id\ChargeId;
use Pagarme\Core\Kernel\ValueObjects\Id\OrderId;
use Pagarme\Core\Maintenance\Services\ConfigInfoRetrieverService;
use Pagarme\Core\Payment\Aggregates\Customer;
use Pagarme\Core\Payment\Aggregates\Order;
use Pagarme\Core\Kernel\ValueObjects\Id\SubscriptionId;
use Pagarme\Core\Recurrence\Aggregates\Invoice;
use Pagarme\Core\Recurrence\Aggregates\Subscription;
use Pagarme\Core\Recurrence\Factories\SubscriptionFactory;

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

class APIService {

	/**
	 * @var MundiAPIClient
	 */
	private $apiClient;

	/**
	 * @var OrderLogService
	 */
	private $logService;

	/**
	 * @var ConfigInfoRetrieverService
	 */
	private $configInfoService;

	/**
	 * @var OrderCreationService
	 */
	private $orderCreationService;

	public function __construct() {
		$this->apiClient            = $this->getMundiPaggApiClient();
		$this->logService           = new OrderLogService( 2 );
		$this->configInfoService    = new ConfigInfoRetrieverService();
		$this->orderCreationService = new OrderCreationService( $this->apiClient );
	}

	public function getCharge( ChargeId $chargeId ) {
		try {
			$chargeController = $this->getChargeController();

			$this->logService->orderInfo(
				$chargeId,
				'Get charge from api'
			);

			$response = $chargeController->getCharge( $chargeId->getValue() );

			$this->logService->orderInfo(
				$chargeId,
				'Get charge response: ',
				$response
			);

			return json_decode( json_encode( $response ), true );
		} catch ( APIException $e ) {
			return $e->getMessage();
		}
	}

	public function cancelCharge( Charge &$charge, $amount = 0 ) {
		try {
			$chargeId        = $charge->getPagarmeId()->getValue();
			$request         = new CreateCancelChargeRequest();
			$request->amount = $amount;

			if ( empty( $amount ) ) {
				$request->amount = $charge->getAmount();
			}

			$chargeController = $this->getChargeController();
			$chargeController->cancelCharge( $chargeId, $request );
			$charge->cancel( $amount );

			return null;
		} catch ( APIException $e ) {
			return $e->getMessage();
		}
	}

	public function captureCharge( Charge &$charge, $amount = 0 ) {
		try {
			$chargeId        = $charge->getPagarmeId()->getValue();
			$request         = new CreateCaptureChargeRequest();
			$request->amount = $amount;

			$chargeController = $this->getChargeController();
			return $chargeController->captureCharge( $chargeId, $request );
		} catch ( APIException $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * @param Order $order
	 * @return array|mixed
	 * @throws Exception
	 */
	public function createOrder( Order $order ) {
		$endpoint = $this->getAPIBaseEndpoint();

		$orderRequest           = $order->convertToSDKRequest();
		$orderRequest->metadata = $this->getRequestMetaData();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'after_covert_order_request', $orderRequest );
		}

		$publicKey = MPSetup::getModuleConfiguration()->getPublicKey()->getValue();

		$configInfo = $this->configInfoService->retrieveInfo( '' );

		$this->logService->orderInfo(
			$order->getCode(),
			"Snapshot config from {$publicKey}",
			$configInfo
		);

		$message =
			'Create order Request from ' .
			$publicKey .
			' to ' .
			$endpoint;

		$this->logService->orderInfo(
			$order->getCode(),
			$message,
			$orderRequest
		);

		try {
			return $this->orderCreationService->createOrder(
				$orderRequest,
				$order->generateIdempotencyKey(),
				3
			);
		} catch ( ErrorException $e ) {
			$this->logService->exception( $e );
			return array( 'message' => $e->getMessage() );
		}
	}

	private function getRequestMetaData() {
		$versionService = new VersionService();
		$metadata       = new \stdClass();

		$metadata->moduleVersion   = $versionService->getModuleVersion();
		$metadata->coreVersion     = $versionService->getCoreVersion();
		$metadata->platformVersion = $versionService->getPlatformVersion();

		return $metadata;
	}

	/**
	 * @param OrderId $orderId
	 * @return AbstractEntity|\Pagarme\Core\Kernel\Aggregates\Order|string
	 * @throws InvalidParamException
	 * @throws NotFoundException
	 */
	public function getOrder( OrderId $orderId ) {
		try {
			$orderController = $this->getOrderController();
			$orderData       = $orderController->getOrder( $orderId->getValue() );

			$orderData = json_decode( json_encode( $orderData ), true );

			$orderFactory = new OrderFactory();

			return $orderFactory->createFromPostData( $orderData );
		} catch ( APIException $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * @return ChargesController
	 */
	private function getChargeController() {
		return $this->apiClient->getCharges();
	}

	/**
	 * @return OrdersController
	 */
	private function getOrderController() {
		return $this->apiClient->getOrders();
	}

	/**
	 * @return CustomersController
	 */
	private function getCustomerController() {
		return $this->apiClient->getCustomers();
	}

	private function getMundiPaggApiClient() {
		$i18n   = new LocalizationService();
		$config = MPSetup::getModuleConfiguration();

		$secretKey = null;
		if ( $config->getSecretKey() != null ) {
			$secretKey = $config->getSecretKey()->getValue();
		}
		$password = '';

		if ( empty( $secretKey ) ) {
			$message = $i18n->getDashboard(
				"Can't connect to the payment service. " .
				'Please contact the store administrator.'
			);

			throw new \Exception( $message, 400 );
		}

		Configuration::$basicAuthPassword = '';

		return new MundiAPIClient( $secretKey, $password );
	}

	private function getAPIBaseEndpoint() {
		return Configuration::$BASEURI;
	}

	public function updateCustomer( Customer $customer ) {
		return $this->getCustomerController()->updateCustomer(
			$customer->getPagarmeId()->getValue(),
			$customer->convertToSDKRequest()
		);
	}

	private function getSubscriptionController() {
		return $this->apiClient->getSubscriptions();
	}

	private function getInvoiceController() {
		return $this->apiClient->getInvoices();
	}

	/**
	 * @param Subscription $subscription
	 * @return mixed|null
	 * @throws APIException
	 */
	public function createSubscription( Subscription $subscription ) {
		$endpoint = $this->getAPIBaseEndpoint();

		$subscription->addMetaData(
			json_decode( json_encode( $this->getRequestMetaData() ), true )
		);

		$subscriptionRequest = $subscription->convertToSDKRequest();
		$publicKey           = MPSetup::getModuleConfiguration()->getPublicKey()->getValue();

		$message =
			'Create subscription request from ' .
			$publicKey .
			' to ' .
			$endpoint;

		$this->logService->orderInfo(
			$subscription->getCode(),
			$message,
			$subscriptionRequest
		);

		$subscriptionController = $this->getSubscriptionController();

		try {
			$response = $subscriptionController->createSubscription( $subscriptionRequest );
			$this->logService->orderInfo(
				$subscription->getCode(),
				'Create subscription response',
				$response
			);

			return json_decode( json_encode( $response ), true );
		} catch ( ErrorException $e ) {
			$this->logService->exception( $e );
			return null;
		}
	}

	/**
	 * @param SubscriptionId $subscriptionId
	 * @return AbstractEntity|Subscription|string
	 * @throws InvalidParamException
	 */
	public function getSubscription( SubscriptionId $subscriptionId ) {
		try {
			$subscriptionController = $this->getSubscriptionController();

			$subscriptionData = $subscriptionController->getSubscription(
				$subscriptionId->getValue()
			);

			$subscriptionData = json_decode( json_encode( $subscriptionData ), true );

			$subscriptionData['interval_type'] = $subscriptionData['interval'];

			$subscriptionFactory = new SubscriptionFactory();
			return $subscriptionFactory->createFromPostData( $subscriptionData );
		} catch ( APIException $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * @param SubscriptionId $subscriptionId
	 * @return mixed|string
	 */
	public function getSubscriptionInvoice( SubscriptionId $subscriptionId ) {
		try {
			$invoiceController = $this->getInvoiceController();

			$this->logService->orderInfo(
				$subscriptionId,
				'Get invoice from subscription.'
			);

			$response = $invoiceController->getInvoices(
				1,
				1,
				null,
				null,
				$subscriptionId->getValue()
			);

			$this->logService->orderInfo(
				$subscriptionId,
				'Invoice response: ',
				$response
			);

			return json_decode( json_encode( $response ), true );
		} catch ( APIException $e ) {
			return $e->getMessage();
		}
	}

	public function cancelSubscription( Subscription $subscription ) {
		$endpoint = $this->getAPIBaseEndpoint();

		$publicKey = MPSetup::getModuleConfiguration()->getPublicKey()->getValue();

		$message =
			'Cancel subscription request from ' .
			$publicKey .
			' to ' .
			$endpoint;

		$this->logService->orderInfo(
			$subscription->getCode(),
			$message
		);

		$subscriptionController = $this->getSubscriptionController();

		try {
			$response = $subscriptionController->cancelSubscription(
				$subscription->getPagarmeId()
			);
			$this->logService->orderInfo(
				$subscription->getCode(),
				'Cancel subscription response',
				$response
			);

			return json_decode( json_encode( $response ), true );
		} catch ( Exception $e ) {
			$this->logService->exception( $e );
			return $e;
		}
	}

	/**
	 * @param Invoice $invoice
	 * @param int $amount
	 * @return mixed
	 * @throws APIException
	 */
	public function cancelInvoice( Invoice &$invoice, $amount = 0 ) {
		try {
			$invoiceId         = $invoice->getPagarmeId()->getValue();
			$invoiceController = $this->apiClient->getInvoices();

			return $invoiceController->cancelInvoice( $invoiceId );
		} catch ( APIException $e ) {
			throw $e;
		}
	}
}
