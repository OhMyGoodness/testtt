<?php

namespace App\Services\Iiko;

use App\Enums\DeliveryType;
use App\Enums\OrderCreationStatus;
use App\Enums\OrderErrorCode;
use App\Enums\PayType;
use App\Helpers\InvoiceHelper;
use App\Models\Company;
use App\Models\Discount;
use App\Models\Iiko\Sync\IikoCitySync;
use App\Models\Iiko\Sync\IikoDiscountsSync;
use App\Models\Iiko\Sync\IikoNomenclatureSync;
use App\Models\Iiko\Sync\IikoNomenclatureSyncDev;
use App\Models\Iiko\Sync\IikoOrganizationSync;
use App\Models\Iiko\Sync\IikoPaymentTypesSync;
use App\Models\Iiko\Sync\IikoRegionsSync;
use App\Models\Iiko\Sync\IikoStreetSync;
use App\Models\Iiko\Sync\IikoTerminalGroupSync;
use App\Models\IikoSyncLog;
use App\Models\IikoTransferLog;
use App\Models\OrdersIikoSync;
use App\Models\StopList;
use App\Models\UserAddress;
use App\Modules\Order\Decorators\OrderDecorator;
use App\Modules\Order\Events\ChangeDeliveryStatusEvent;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Package\Iiko\Requests\CreateDeliveryRequest;
use App\Package\Iiko\Requests\CreateDeliverySettingsRequest;
use App\Package\Iiko\Requests\CreateOrderRequest;
use App\Package\Iiko\Requests\DeliveryCustomerRequest;
use App\Package\Iiko\Requests\DeliveryOrderItemModifierRequest;
use App\Package\Iiko\Requests\DeliveryOrderItemRequest;
use App\Package\Iiko\Requests\DeliveryPaymentsRequest;
use App\Package\Iiko\Requests\DeliveryPointAddressRequest;
use App\Package\Iiko\Requests\DeliveryPointAddressStreetRequest;
use App\Package\Iiko\Requests\DeliveryPointCoordinatesRequest;
use App\Package\Iiko\Requests\DeliveryPointRequest;
use App\Package\Iiko\Requests\DeliveryRequest;
use App\Package\Iiko\Requests\DiscountsInfoRequest;
use App\Package\Iiko\Requests\GetOrdersByIDsRequest;
use App\Package\Iiko\Requests\OrderCustomerRequest;
use App\Package\Iiko\Requests\OrderItemRequest;
use App\Package\Iiko\Requests\OrderRequest;
use App\Services\Company\CompanyService;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderRepositoryService;
use Throwable;
use Yobi\Iiko\IikoCloudService;
use Yobi\Iiko\Requests\AuthorizeRequest;
use Yobi\Iiko\Requests\GetCitiesRequest;
use Yobi\Iiko\Requests\GetDiscountsRequest;
use Yobi\Iiko\Requests\GetNomenclatureRequest;
use Yobi\Iiko\Requests\GetOrganizationsRequest;
use Yobi\Iiko\Requests\GetPaymentTypesRequest;
use Yobi\Iiko\Requests\GetStopListsRequest;
use Yobi\Iiko\Requests\GetStreetsRequest;
use Yobi\Iiko\Requests\GetTerminalGroupsRequest;
use Yobi\Iiko\Requests\IikoRegionRequest;
use Yobi\Iiko\Resources\TerminalGroupStopListItemResource;
use function collect;
use function config;

class IikoCloudTransferService
{
    public static $sendIikoAmountMax = 1;
    const PROD_VERSION = 'prod';
    const PROD_VERSION_KZ = 'kz';
    const PROD_VERSION_SUSHIMASTER = 'sushimaster';

    /**
     * @param IikoCloudService $client
     */
    public function __construct(private IikoCloudService $client)
    {
        //
    }

    public function organisations(string $token): void
    {
        $log = IikoSyncLog::create([
            'type'   => 'is_organization',
            'status' => 'pending'
        ]);

        $auth = $this->client->request(new AuthorizeRequest($token));
        $organizations = collect($this->client->request(new GetOrganizationsRequest($auth->resource->token))->resource);

        IikoLogger::info(sprintf('Start syncing %d organizations with %d version', $organizations->count(), $log->id));

        (new IIKoOrganizationSync($organizations->toArray(), $log->id))();

        IikoLogger::info(sprintf(sprintf('Total %d organizations synced with %d version', $organizations->count(), $log->id)));
    }

    public function terminalGroup(string $token): void
    {
        $log = IikoSyncLog::create([
            'type'   => 'is_terminal_group',
            'status' => 'pending',
        ]);
        $auth = $this->client->request(new AuthorizeRequest($token));
        $organizations = Company::all(['source_id']);

        IikoLogger::info(sprintf('Start syncing terminal groups for all companies (count: %d) %d version', $organizations->count(), $log->id));

        $terminals = $this->client->request(
            new GetTerminalGroupsRequest(
                $auth->resource->token,
                $organizations->pluck('source_id')->toArray()
            )
        );

        (new IIKoTerminalGroupSync($terminals->items->toArray(), $log->id))();

        IikoLogger::info(sprintf(sprintf('Total %d terminal groups synced with %d version', $terminals->items->count(), $log->id)));
    }

    public function cities(string $token): void
    {
        $log = IikoSyncLog::query()->create([
            'type'   => 'is_city',
            'status' => 'pending',
        ]);

        $organizations = Company::query()
            ->select(['source_id'])
            ->where('version', IikoSyncLog::query()->where('type', 'is_organization')->max('id'))
            ->get();

        $auth = $this->client->request(new AuthorizeRequest($token));

        $cities = $this->client->request(
            new GetCitiesRequest(
                $auth->resource->token,
                $organizations->pluck('source_id')->toArray()
            )
        );

        IikoLogger::info(sprintf('Start syncing cities with %d version', $log->id));

        (new IIKoCitySync($cities->items, $log->id))();

        IikoLogger::info(sprintf(sprintf('Cities synced with %d version', $log->id)));
    }

    /**
     * @param string $token
     * @return void
     */
    public function streets(string $token): void
    {
        $log = IikoSyncLog::query()->create([
            'type'   => 'is_street',
            'status' => 'pending',
        ]);

        $companies = Company::with('iiko_city')->where('version', IikoSyncLog::query()->where('type', 'is_organization')->max('id'))->get();
        $auth = $this->client->request(new AuthorizeRequest($token));

        IikoLogger::info(sprintf('Start syncing streets with %d version', $log->id));

        $companies->map(function ($company) use ($auth, $log) {
            if ($company->iiko_city != null) {
                $streetsResponse = $this->client->request(new GetStreetsRequest(
                    $auth->resource->token,
                    $company->source_id,
                    $company->iiko_city->source_id
                ));
                IikoLogger::info(sprintf('Syncing streets with %d version', $log->id));

                (new IikoStreetSync($streetsResponse->resource, $log->id))($company->iiko_city->source_id, $company->source_id);

                IikoLogger::info(sprintf(sprintf('Streets synced with %d version', $log->id)));
            }
        });
    }

    public function nomenclature($token): void
    {
        $log = IikoSyncLog::create([
            'type'   => 'is_nomenclature',
            'status' => 'pending'
        ]);

        $organizations = Company::query()->whereNotNull('city_id')->get(['source_id', 'name']);
        $auth = $this->client->request(new AuthorizeRequest($token));

        $nomenclatures = $organizations->map(function ($org) use ($auth) {

            $nomenclatureResponse = $this->client->request(new GetNomenclatureRequest(
                $auth->resource->token,
                $org->source_id
            ));

            IikoLogger::info(sprintf('Start syncing nomenclatures. Organization: %s', $org->name));

            return $nomenclatureResponse->resource;
        })->all();

        (new IikoNomenclatureSync($nomenclatures, $log->id))();

        IikoLogger::info(sprintf('Total %d nomenclatures synced with %d version', count($nomenclatures), $log->id));
    }

    public function nomenclatureDev($token): void
    {
        $auth = $this->client->request(new AuthorizeRequest($token));
        $api_organisation_id = config('services.ikko.api_organisation_id');
        $nomenclature = $this->client->request(new GetNomenclatureRequest(
            $auth->resource->token,
            $api_organisation_id
        ));

        (new IikoNomenclatureSyncDev($nomenclature->resource))();
    }

    public function stopList($token): void
    {
        $organizations = Company::query()
            ->select(['source_id'])
            ->where('version', IikoSyncLog::query()->where('type', 'is_organization')->max('id'))
            ->get()
            ->pluck('source_id')
            ->toArray();

        $auth = $this->client->request(new AuthorizeRequest($token));
        $orgStopListResponse = $this->client->request(new GetStopListsRequest(
            $auth->resource->token,
            $organizations
        ));

        IikoLogger::info(sprintf('Start syncing stop lists.'));

        foreach ($orgStopListResponse->resource as $orgStopList) {
            foreach ($orgStopList->terminalGroupStopLists as $terminalGroupStopList) {
                IikoLogger::info(sprintf('Syncing stop lists. Terminal group ID: %s', $terminalGroupStopList->terminalGroupId));

                StopList::query()
                    ->where('company_id', $orgStopList->organizationId)
                    ->where('terminal_group_id', $terminalGroupStopList->terminalGroupId)
                    ->whereNotIn('product_id', collect($terminalGroupStopList->items)->pluck('productId')->all())
                    ->delete();

                StopList::query()->upsert(
                    collect($terminalGroupStopList->items)->map(function (TerminalGroupStopListItemResource $source) use ($orgStopList, $terminalGroupStopList) {
                        return [
                            'company_id'        => $orgStopList->organizationId,
                            'terminal_group_id' => $terminalGroupStopList->terminalGroupId,
                            'product_id'        => $source->productId,
                            'balance'           => $source->balance,
                        ];
                    })->all(),
                    ['company_id', 'terminal_group_id', 'product_id'],
                    ['balance']
                );
            }
        }

        StopList::query()->where('balance', '>', 0)->delete();
    }

    public function createOrder(string $token)
    {
        $auth = $this->client->request(new AuthorizeRequest($token));

        $organizationId = "fbe9d2de-ee58-49c2-a348-f9cd049acbf2";
        $terminalGroupId = "9ab67ee2-7e3c-48dd-897a-f46af06cc008";
        $orderItems = [
            [
                "id"        => "edafbd73-d4b7-447f-ac62-11e589cc7749",
                "sum"       => 50,
                "name"      => "Тестовый заказ. Обрабатывать не нужно.",
                "amount"    => 1,
                "comment"   => "Тестовый заказ. Обрабатывать не нужно",
                "modifiers" => [

                ]
            ],
            [
                "id"        => "a2eec47b-86fc-4ede-a2fa-df5ff16453dd",
                "sum"       => 1999,
                "name"      => "Тестовый заказ. Обрабатывать не нужно",
                "amount"    => 1, 23,
                "comment"   => "Тестовый заказ. Обрабатывать не нужно",
                "modifiers" => [

                ]
            ],
        ];
        return $this->client->request(new CreateOrderRequest(
            $auth->resource->token,
            $organizationId,  // No source (organisation wrong ID)
            $terminalGroupId, // No source (terminalGroup wrong ID)
            new OrderRequest(
                array_map(function ($item) {
                    return new OrderItemRequest(
                        $item['id'],
                        $item['modifiers'],
                        null,     // No source (price)
                        null,     // No source (position id)
                        'Product',// No source (type)
                        $item['amount'],
                        null,// No source (product size id)
                        null,// No source (combo information)
                        'test-' . $item['comment']
                    );
                }, $orderItems),
                null,
                null,// No source (table ids)
                new OrderCustomerRequest(
                    null,
                    'Тестовый заказ. Обрабатывать не нужно',
                    'Тестовый заказ. Обрабатывать не нужно',
                    'Тестовый заказ. Обрабатывать не нужно',// No source (comment)
                    null,                                   // No source (birthdate)
                    null,                                   // No source (email)
                    false,
                    'NotSpecified'
                ),
                '+77777777',
                null,// No source (combos)
                null,// No source (payments)
                null,// No source (tips)
                null,// No source (sourceKey)
                null,// No source (discountsIfo)
                null,// No source (iikoCard5Info)
                '76067ea3-356f-eb93-9d14-1fa00d082c4e'// No source (order Type id)
            )
        ));
    }

    /**
     * @param string $token
     * @return bool
     */
    public function createDelivery(string $token): bool
    {
        $auth = $this->client->request(new AuthorizeRequest($token));
        $organizationId = 'b2bd5120-f004-4e2b-aade-e0a51bdd3757';
        $terminalGroupId = "fe2a4ca6-b221-4092-bdd8-51acef388737";

        try {
            $delivery = $this->client->request(new CreateDeliveryRequest(
                $auth->resource->token,
                $organizationId,
                $terminalGroupId,
                (new DeliveryRequest(
                    [
                        new DeliveryOrderItemRequest(
                            "b5455256-1a60-4068-aa40-d0f322a990ee",
                            [],
                            "Product",
                            2,
                            null
                        )
                    ],
                    (new DeliveryPointRequest(
                        (new DeliveryPointCoordinatesRequest(
                            123,
                            123
                        )),
                        (new DeliveryPointAddressRequest(
                            (new DeliveryPointAddressStreetRequest(
                                null,
                                '063f4b4b-df4f-a111-0162-66352fc6a167',
                                '13 квартал гаражно-строительный кооп.',
                                null
                            )),
                            'Тест',
                        )),
                        null,
                        null
                    )),
                    null,
                    new DeliveryCustomerRequest(
                        null,
                        'Тестовый заказ. Обрабатывать не нужно',
                        'Тестовый заказ. Обрабатывать не нужно',
                        'Тестовый заказ. Обрабатывать не нужно',
                        null,
                        null,
                        false,
                        1
                    ),
                    '+777777777',
                    null,
                    [new DeliveryPaymentsRequest(
                        'Cash',
                        10.00,
                        '09322f46-578a-d210-add7-eec222a08871',
                        false,
                        null,
                        false
                    )],
                    null,
                    null,
                    null,
                    null,
                    'DeliveryByCourier'
                ))
            ));

            Log::info('Create Delivery', [
                'correlationId'  => $delivery->correlationId,
                'deliveryId'     => $delivery->resource->id,
                'organizationId' => $delivery->resource->organizationId,
                'creationStatus' => $delivery->resource->creationStatus,
                'timestamp'      => Carbon::parse($delivery->resource->timestamp / 1000)->format('Y-m-d H:i:s'),
                'errorInfo'      => $delivery->resource->errorInfo,
                'order'          => $delivery->resource->order,
            ]);

            return true;
        } catch (Exception $e) {
            echo $e->getMessage();

            return false;
        }
    }

    /**
     * @param string $token
     * @param string $organizationId
     * @param array $orderIds
     * @return GetOrdersByIDsRequest|Exception|mixed
     */
    public function getOrderRequest(string $token, string $organizationId, array $orderIds): mixed
    {
        try {
            return $this->client->request(new GetOrdersByIDsRequest(
                $token,
                $organizationId,
                $orderIds
            ));
        } catch (Exception $e) {
            return $e;
        }
    }

    /**
     * @param string $token
     * @param string $organizationId
     * @param array $orderIds
     * @return bool
     */
    public function getOrderById(string $token, string $organizationId, array $orderIds): bool
    {
        $auth = $this->client->request(new AuthorizeRequest($token));
        $delivery = $this->getOrderRequest($auth->resource->token, $organizationId, $orderIds);
        Log::info('Get Delivery', [
            $delivery
        ]);

        return true;
    }

    /**
     * @param string $token
     * @param int|null $orderId
     * @return void
     */
    public function ordersSync(string $token, ?int $orderId = null): void {
        $auth = $this->client->request(new AuthorizeRequest($token));
        $date_limit = Carbon::now()->subHours(1);

        $orders = Order::query()
            ->where(function ($q) {
                $q->where('status', '<', 99)
                    ->orWhere(function ($q1) {
                        $q1->where('status', 99)
                           ->where('error_code', OrderErrorCode::TIMEOUT);
                    });
            })
            ->where('send_iiko_amount', '<', self::$sendIikoAmountMax)
            ->where('created_at', '>=', $date_limit)
            ->with([
                    'company' => function ($q) {
                        return $q->with(['terminal', 'orders' => function ($query) {
                                return $query->with(
                                    [
                                        'products' => function ($qPr) {
                                            return $qPr->with(['price']);
                                        }
                                    ]
                                );
                            }
                            ]
                        );
                    },
                    'gift',
                    'user'    => function ($uQ) {
                        return $uQ->with('userAddresses');
                    },
                    'paymentType'
                ]
            );

        $orders->where(function ($q) {
            $q->where('pay_type', '!=', PayType::INET)
                ->orWhere(function ($q1) {
                    $q1->where('pay_type', PayType::INET)->where('payment_status', PaymentStatus::SUCCESS);
                });
        });

        if ($orderId !== null) {
            $orders->where('id', $orderId);
        } else {
            $orderIds = OrdersIikoSync::query()
                ->where('created_at', '>=', $date_limit)
                ->select('order_id')
                ->get()
                ->pluck('order_id')->toArray();

            $orders->whereNotIn('id', $orderIds);
        }

        $lastVersion = IikoSyncLog::query()
            ->where('type', 'is_discount')
            ->where('status', 'success')
            ->orderBy('id', 'DESC')
            ->first();

        $orders->get()->map(function (Order $order) use ($auth, $lastVersion) {
            try {
                Log::info("#iiko #createDelivery", ['order.id' => $order->id]);

                if ($order->order_products->count() == 0) {
                    $order->update([
                        'status'               => OrderCreationStatus::Error,
                        'number'               => null,
                        'expected_delivery_at' => null,
                        'error_code'           => OrderErrorCode::CRITICAL_CART_IS_EMPTY,
                    ]);

                    OrdersIikoSync::query()->create([
                        'order_id'       => $order->id,
                        'status'         => 'Error',
                        'correlation_id' => '00000000-0000-0000-0000-000000000000',
                        'iiko_order_id'  => '00000000-0000-0000-0000-000000000000'
                    ]);

                    $helperData = [
                        'Id'         => $order->helper_id,
                        'IsError'    => true,
                        'IikoStatus' => OrderErrorCode::CRITICAL_CART_IS_EMPTY->value,
                        'InvoiceId' => InvoiceHelper::generateId($order->id),
                    ];
                } else {
                    $current_zone = $order->delivery_type != 0 ? $order->getCurrentRegion() : null;
                    $userAddress = UserAddress::where('id', $order->address_id)->first();

                    //TODO надо отрефакторить
                    $paymentsEnum = ['CASH' => '09322f46-578a-d210-add7-eec222a08871', 'VISA' => '9cd5d67a-89b4-ab69-1365-7b8c51865a90', 'INET' => '9bf4bd8d-a973-418d-8938-2cb3ed271aa4'];
                    $marketingSourceId = '464e9b18-58b6-475d-bbb8-3d6929eed902';

                    if (config('iiko.app_target') === self::PROD_VERSION_KZ) {
                        $paymentsEnum = ['CASH' => '09322f46-578a-d210-add7-eec222a08871', 'VISA' => '0ada42f8-ba5c-4453-ba06-db6ec05497ec', 'INET' => 'c8d30f6c-f244-4c62-9523-f9bda52c0853'];

                        if ($order->is_mobile) {
                            $marketingSourceId = '87c29524-b912-49a1-86cc-8df3d6e4300b';
                        }else{
                            $marketingSourceId = '8846e6fe-6595-4f4d-b5b6-b7636029bf96';
                        }
                    } elseif(config('iiko.app_target') === self::PROD_VERSION_SUSHIMASTER){
                        $paymentsEnum = ['CASH' => '09322f46-578a-d210-add7-eec222a08871', 'VISA' => '3ef263d5-7588-4295-821e-6bccf1b81627', 'INET' => '262a1069-db37-42f1-8e61-8108b7454ce6'];
                        if ($order->is_mobile) {
                            $marketingSourceId = '891fa83b-c62c-4983-8826-86184884b637';
                        }else{
                            $marketingSourceId = '3e6cade7-442d-43c7-8264-2b70953fc1f8';
                        }

                    } elseif ($order->is_mobile) {
                        $marketingSourceId = '2023d44c-ac90-4352-a267-023b528603d2';
                    }

                    $discountId = null;

                    if ($order->delivery_type == 0 || ($order->delivery_type == 1 && $order->birthday)) {
                        $discountQuery = Discount::query()
                            ->where('name', 'LIKE', '%Ёби%')
                            /*->where('company_id', $order->company_id)*/
                            ->where('version', '>=', $lastVersion->id);
                        if ($order->delivery_type == 0) {
                            $discountQuery->where('name', 'LIKE', '%самовывоз%');
                        } else {
                            $discountQuery->where('name', 'LIKE', '%доставка%');
                        }

                        if ($order->birthday) {
                            if ($order->delivery_type == 0) {
                                $discountQuery->where('name', 'LIKE', '%др%');
                            } else {
                                $discountQuery->where('name', 'LIKE', '%д.р.%');
                            }
                        } elseif ($order->delivery_type == 0) {
                            $discountQuery->where('name', 'NOT LIKE', '%др%');
                        }

                        try {
                            $discountId = $discountQuery->firstOrFail()->source_id;
                        } catch (Throwable $e) {
                            Log::alert("Not able to fetch discount for order", ['order_id' => $order->id]);
                        }
                    }

                    $userAddressString = $userAddress->address->address ?? null;

                    if (mb_strlen($userAddress->address->homeNumber ?? null)) {
                        $userAddressString .= " дом {$userAddress->address->homeNumber}";
                    }

                    if (mb_strlen($userAddress->address->building ?? null)) {
                        $userAddressString .= " стр. {$userAddress->address->building}";
                    }

                    if (mb_strlen($userAddress->entrance ?? null)) {
                        $userAddressString .= " под. {$userAddress->entrance}";
                    }

                    if (mb_strlen($userAddress->floor ?? null)) {
                        $userAddressString .= " этаж {$userAddress->floor}";
                    }

                    if (mb_strlen($userAddress->apartment ?? null)) {
                        $userAddressString .= " кв. {$userAddress->apartment}";
                    }

                    if (mb_strlen($userAddress->comment ?? null)) {
                        $userAddressString .= " коммент.: {$userAddress->comment}";
                    }

                    $create_delivery_request = new CreateDeliveryRequest(
                        $auth->resource->token,
                        $order->company->source_id,
                        $order->company->terminal->source_id,
                        (new DeliveryRequest(
                            $order->order_products->map(function ($product) use ($order) {
                                /*                                $product->modifiers->map(function($mod) {
                                                                    Log::info("Try create modifiers", [$mod->product]);
                                                                });*/
                                return new DeliveryOrderItemRequest(
                                    $product->product->source_id,
                                    $product->modifiers->map(function ($mod) use ($product) {
                                        return new DeliveryOrderItemModifierRequest($mod->product->source_id, 1, $product->modifierGroup->first()->source_id);
                                    })->toArray(),
                                    'Product',
                                    (float)$product->quantity,
                                    null,
                                    null,//$product->price->currentPrice,
                                    null
                                );
                            })->toArray(),
                            /*CompanyService::getProperDeliveryTime($order->company->utc_offset, $order->delivery_at, $current_zone->min_delivery_time ?? 30),*/
                            $order->delivery_at . ".000",
                            ($order->delivery_type == 0 ? null : new DeliveryPointRequest(
                                (new DeliveryPointCoordinatesRequest(
                                    $userAddress->address->lat,
                                    $userAddress->address->lng
                                )),
                                (new DeliveryPointAddressRequest(
                                    (new DeliveryPointAddressStreetRequest(
                                        $userAddress->address->classifier_id,
                                        null,
                                        null, /*$order->user->userAddresses->address->address,*/
                                        $userAddress->address->city->name
                                    )),
                                    empty($userAddress->address->homeNumber) ? 0 : $userAddress->address->homeNumber,
                                    $userAddress->address->building,
                                    $userAddress->apartment, //
                                    $userAddress->entrance,
                                    $userAddress->floor,
                                    $userAddress->intercom,
                                    $current_zone->source_id ?? null
                                )),
                                $userAddress->comment
                            )),
                            null,
                            new DeliveryCustomerRequest(
                                $order->user->profile->name,
                                null,
                                null,
                                null,
                                null,
                            ),
                            '+' . $order->user->phone,
                            null,
                            [
                                new DeliveryPaymentsRequest(
                                    PayType::getPaymentForIikoText($order->pay_type),
                                    (PayType::from($order->pay_type)->name == 'CASH' && $order->change != null && $order->change > $order->final_sum) ? intval($order->change) :
                                        floor($order->final_sum),
                                    $paymentsEnum[PayType::from($order->pay_type)->name],
                                    $order->pay_type == PayType::INET->value,
                                    null,
                                    false
                                )
                            ],
                            DeliveryType::from($order->delivery_type)->name,
                            ((config('iiko.app_target') == self::PROD_VERSION_KZ) ? $userAddressString . ' // ' : '')
                            . ($order->company->name == 'Зеленоград_1 Зел' ? ($userAddress->address->address ?? null) . ' // ' : '')
                            . ((isset($order->comment) ? $order->comment :"")
                            . (isset($userAddress->comment) ? '//' . $userAddress->comment : "") ?? null)
                            . ($order->birthday ? "/ДР" : ""),
                            $marketingSourceId,
                            $discountId ? new DiscountsInfoRequest($discountId) : null,
                        )),
                        new CreateDeliverySettingsRequest(45)
                    );
                    $result = $this->client->request($create_delivery_request);

                    $order->update([
                        'send_iiko_amount'     => $order->send_iiko_amount+1
                    ]);

                    $helperData = [
                        'Id'         => $order->helper_id,
                        'IsError'    => false,
                        'IikoStatus' => $result->resource->creationStatus,

                    ];

                    if ($order->helper_id != null) {
                        $helperDataUpdate = [
                            'Id'                 => $order->helper_id,
                            'IsError'            => false,
                            'IikoStatus'         => $result->resource->creationStatus,
                            'DeliveryOrderId'    => $result->resource->id,
                            'OrganizationIikoId' => $order->company->source_id,
                        ];

                        try {
                            $client = new \GuzzleHttp\Client();
                            $client->patch('https://' . config('app.helper_subdomain') . '.ybdyb.ru/Api/Orders/Update', [
                                RequestOptions::JSON => $helperDataUpdate
                            ]);
                        }
                        catch (Exception $ex){
                            //
                        }
                    }

                    Log::info('#iiko #createDelivery', [
                        'correlationId'  => $result->correlationId,
                        'deliveryId'     => $result->resource->id,
                        'organizationId' => $result->resource->organizationId,
                        'creationStatus' => $result->resource->creationStatus,
                        'timestamp'      => Carbon::parse($result->resource->timestamp / 1000)->format('Y-m-d H:i:s'),
                        'errorInfo'      => $result->resource->errorInfo,
                        'order'          => $result->resource->order,
                        'helper_id'      => $order->helper_id,
                        'region_id'      => isset($current_zone->source_id) ? $current_zone->source_id : "null",
                        'payment_type'   => ucfirst(mb_strtolower(PayType::from($order->pay_type)->name))
                    ]);

                    OrdersIikoSync::query()->create([
                        'order_id'       => $order->id,
                        'status'         => $result->resource->creationStatus,
                        'correlation_id' => $result->correlationId,
                        'iiko_order_id'  => $result->resource->id
                    ]);
                }
            } catch (Exception $e) {
                // тут скорей всего будут ошибки, которые связаны с кодом, а не отправкой сообщений от айки
                Log::error("#iiko #createDelivery #error: File: {$e->getFile()}, Line: {$e->getLine()}, Messgage {$e->getMessage()}");

                IikoTransferLog::create([
                    'order_id' => $order->id,
                    'message' => "CRITICAL! File: {$e->getFile()}, Line: {$e->getLine()}, Messgage {$e->getMessage()}",
                    'response' => [],
                ]);

                $order->update([
                    'status'               => OrderCreationStatus::Error,
                    'number'               => null,
                    'expected_delivery_at' => null,
                    'error_code'           => OrderErrorCode::getCode($e->getMessage(), OrderErrorCode::CRITICAL),
                    'send_iiko_amount'     => $order->send_iiko_amount+1
                ]);

                $helperData = [
                    'Id'         => $order->helper_id,
                    'IsError'    => true,
                    'IikoStatus' => $e->getMessage(),
                    'InvoiceId' => InvoiceHelper::generateId($order->id),
                ];
            }

            if ($order->helper_id != null) {
                $client = new \GuzzleHttp\Client();
                $client->patch('https://' . config('app.helper_subdomain') . '.ybdyb.ru/Api/Orders/Update', [
                    RequestOptions::JSON => $helperData
                ]);
            }
        });
    }

    /**
     * @param $id
     * @return array
     */
    public static function getPayTypeByDb($id): array
    {
        return match ($id) {
            1 => [
                'id'                    => '09322f46-578a-d210-add7-eec222a08871',
                'code'                  => 'Cash',
                'name'                  => 'Наличные',
                'combinable'            => true,
                'isDeleted'             => false,
                'paymentProcessingType' => 'Both',
                'paymentTypeKind'       => 'Cash'
            ],
            2 => [
                'id'                    => '31b5f6a3-7d1f-43a0-b023-d12a77bbbd8c',
                'code'                  => 'DKPAY',
                'name'                  => 'Оплата Деливери Клаб',
                'combinable'            => false,
                'isDeleted'             => false,
                'paymentProcessingType' => 'Both',
                'paymentTypeKind'       => 'Card'
            ],
            3 => [
                'id'                    => '52f56416-b475-47d7-9ee3-5e0914900845',
                'code'                  => 'YDPAY',
                'name'                  => 'Оплата Яндекс Еда',
                'combinable'            => true,
                'isDeleted'             => false,
                'paymentProcessingType' => 'Both',
                'paymentTypeKind'       => 'Card'
            ],
            4 => [
                'id'                    => '9bf4bd8d-a973-418d-8938-2cb3ed271aa4',
                'code'                  => 'INET',
                'name'                  => 'Оплата на сайте',
                'combinable'            => true,
                'isDeleted'             => false,
                'paymentProcessingType' => 'Both',
                'paymentTypeKind'       => 'Card'
            ],
            5 => [
                'id'                    => '9cd5d67a-89b4-ab69-1365-7b8c51865a90',
                'code'                  => 'VISA',
                'name'                  => 'Visa',
                'combinable'            => true,
                'isDeleted'             => false,
                'paymentProcessingType' => 'Internal',
                'paymentTypeKind'       => 'Card'
            ],
            default => 'Case not found!',
        };
    }

    /**
     * @param $id
     * @return array|string
     */
    public static function getOrderServiceTypeById($id): array|string
    {
        return match ($id) {
            1 => [
                'id'               => '5b1508f9-fe5b-d6af-cb8d-043af587d5c2',
                'orderServiceType' => 'DeliveryPickUp',
                'name'             => 'Доставка самовывоз',
            ],
            2 => [
                'id'               => '68b10546-3480-4fce-8a33-f14083ba618b',
                'orderServiceType' => 'DeliveryByCourier',
                'name'             => 'Платная',
            ],
            3 => [
                'id'               => '76067ea3-356f-eb93-9d14-1fa00d082c4e',
                'orderServiceType' => 'DeliveryByCourier',
                'name'             => 'Доставка курьером',
            ],
            4 => [
                'id'               => 'bbbef4dc-5a02-7ea3-81d3-826f4e8bb3e0',
                'orderServiceType' => 'Common',
                'name'             => 'Обычный заказ',
            ],
            default => 'Case not found!',
        };
    }

    /**
     * @param string $apiLogin
     * @return bool
     */
    public function changeDeliveryStatus(string $apiLogin): bool
    {
        try {
            $auth = $this->client->request(new AuthorizeRequest($apiLogin));
            $orders = OrdersIikoSync::query()
                ->where([['created_at', '>', Carbon::now()->subDays(1)], ['created_at', '<', Carbon::now()->subSeconds(90)]])
                ->with([
                    'order' => function ($q) {
                        return $q->with('company');
                    }
                ])
                ->whereHas('order', function ($q) {
                    $q->whereNotIn('status', [OrderCreationStatus::Cancelled, OrderCreationStatus::Error, OrderCreationStatus::Closed]);
                })
                ->get();

            $orders->map(function ($orderDTO) use ($auth) {
                collect($this->getOrderRequest(
                    $auth->resource->token,
                    $orderDTO->order->company->source_id,
                    [$orderDTO->iiko_order_id]
                )->orders)->map(function ($iikoOrder) use ($orderDTO) {
                    if (!isset($iikoOrder->order->status)) {
                        Log::info("Creation status: ", [$iikoOrder]);
                    } else if (OrderCreationStatus::fromIiko($iikoOrder->order->status)->value >= 8 && OrderCreationStatus::fromIiko($iikoOrder->order->status)->value <= 10) {
                        OrderRepositoryService::orderDelivered($orderDTO->order_id);
                    }

                    if (isset($iikoOrder->order) || isset($iikoOrder->errorInfo) || isset($iikoOrder->error)) {
                        $error_code = null;
                        $hasStatus = isset($iikoOrder->order->status);

                        if (!$hasStatus) { // Если ошибка

                            $message = isset($iikoOrder->errorInfo) ? $iikoOrder->errorInfo->message : $iikoOrder->errorDescription;
                            $error_code = OrderErrorCode::getCode($message, OrderErrorCode::UNKNOWN);

                            if ($error_code == OrderErrorCode::TIMEOUT) {
                                //OrdersIikoSync::query()->where('order_id', $orderDTO->order_id)->delete();
                            }

                            IikoTransferLog::create([
                                'order_id' => $orderDTO->order_id,
                                'message'  => $message ?? null,
                                'response' => (array)($iikoOrder->errorInfo ?? []),
                            ]);
                        }
                        $iikoStatus = null;

                        if($hasStatus)
                        {
                            $iikoStatus =  OrderCreationStatus::fromIiko($iikoOrder->order->status)->value;
                        }

                        $orderId = $orderDTO->order_id;
                        $number = $hasStatus ? $iikoOrder->order->number : null;

                        Order::query()
                            ->where('id', $orderDTO->order_id)
                            ->update([
                                'status'               => $hasStatus ? $iikoStatus : OrderCreationStatus::Error,
                                'number'               => $number,
                                'expected_delivery_at' => $hasStatus ? $iikoOrder->order->completeBefore : null,
                                'error_code'           => $error_code,
                            ]);

                        if($iikoStatus &&
                            $iikoStatus !== OrderCreationStatus::Closed->value &&
                            $orderDTO->order->status < $iikoStatus) {

                            ///** @var Order $order */
                            $orderDTO2 = $orderDTO->order;
                            event(new ChangeDeliveryStatusEvent(
                                new OrderDecorator(
                                    $orderDTO2->getUser(),
                                    $orderId,
                                    $number,
                                    $iikoStatus,
                                    $orderDTO2->isMobile()
                                )
                            ));
                        }

                        if ($orderDTO->order->send_iiko_amount < self::$sendIikoAmountMax && $error_code == OrderErrorCode::TIMEOUT) {
                            try {
                                $client = new Client();
                                $client->request('GET', 'https://' . config('app.helper_subdomain') . '.ybdyb.ru/Api/Orders/WaitOrder/' . $orderDTO->order->helper_id);
                            } catch (Exception $ex) {
                                // чтобы не вызывать exception выше :(
                            }
                        }
                    }
                });
            });

            return true;
        } catch (Exception $e) {
            Log::error("#error #iiko_status_sync, " . $e->getMessage() . ', ' . $e->getLine());
            return false;
        }
    }

    /**
     * @param string $token
     * @return void
     */
    public function paymentTypes(string $token): void
    {
        $log = IikoSyncLog::query()
            ->create([
                'type'   => 'is_payment_type',
                'status' => 'pending',
            ]);
        $organizationIds = Company::query()
            ->get(['source_id'])
            ->pluck('source_id')
            ->toArray();

        $auth = $this->client->request(new AuthorizeRequest($token));
        $types = collect($this->client
            ->request(new GetPaymentTypesRequest($auth->resource->token, $organizationIds))
            ->resource)
            ->toArray();

            IikoLogger::info(sprintf('Start syncing payment types with %d version', $log->id));

            (new IikoPaymentTypesSync($types, $log->id))();
    }

    /**
     * Regions
     *
     * @param $token
     * @return void
     */
    public function regions($token): void
    {
        $log = IikoSyncLog::query()
            ->create([
                'type'   => 'is_region',
                'status' => 'pending',
            ]);
        $organizationIds = Company::query()
            ->where('version', IikoSyncLog::query()->where('type', 'is_organization')->max('id'))
            ->get(['source_id'])
            ->pluck('source_id')
            ->toArray();

        $auth = $this->client->request(new AuthorizeRequest($token));
        $response = $this->client->request(new IikoRegionRequest($auth->resource->token, $organizationIds));

        IikoLogger::info(sprintf('Start syncing regions with %d version', $log->id));

        (new IikoRegionsSync($response->items, $log->id))();
    }

    public function discounts(string $token)
    {
        $log = IikoSyncLog::query()
            ->create([
                'type'   => 'is_discount',
                'status' => 'pending',
            ]);
        $organizationIds = Company::query()
            ->where('version', IikoSyncLog::query()->where('type', 'is_organization')->max('id'))
            ->get(['source_id'])
            ->pluck('source_id')
            ->toArray();

        $auth = $this->client->request(new AuthorizeRequest($token));
        $response = $this->client->request(new GetDiscountsRequest($auth->resource->token, $organizationIds));

        return (new IikoDiscountsSync($response->resource, $log->id))();
    }
}
