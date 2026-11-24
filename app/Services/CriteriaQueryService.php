<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Support\Facades\Log;

class CriteriaQueryService
{
    protected $criteria = [];

    public function __construct()
    {
        $this->criteria = [
            'has_order' => 'hasOrder',
            'does_not_have_order' => 'doesNotHaveOrder',
            'order_not_completed' => 'orderNotCompleted',
            'order_price_more_than' => 'orderPriceMoreThan',
            'order_price_less_than' => 'orderPriceLessThan',
            'more_than_order_count' => 'moreThanOrderCount',
            'less_than_order_count' => 'lessThanOrderCount',
            'order_rated_more_than' => 'orderRatedMoreThan',
            'order_rated_less_than' => 'orderRatedLessThan',
        ];
    }

    public function applyCustomerCriteria(Builder $query, array $criteria, $except = null)
    {
        foreach ($criteria as $criterion) {
            $key = $criterion['type'];
            if (isset($this->criteria[$key])) {
                $query = $this->{$this->criteria[$key]}($query, $criterion ?? [], $except);
            }
        }

        return $query;
    }

    public function hasOrder(Builder $query, $params, $except = null)
    {
        $orderFilter = function ($q) use ($params, $except) {
            $q->where('status', '!=', OrderStatus::CANCELLED->value)
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })->when(isset($params['additional']['duration']) && $params['additional']['duration'] > 0, function ($q) use ($params) {
                    $q->where('created_at', '>=', Carbon::now()->subDays((int) $params['additional']['duration']));
                })->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        };

        return $query
            ->whereHas('orders', $orderFilter)
            ->when(isset($params['additional']['count']) && (int) $params['additional']['count'] > 0, function ($q) use ($orderFilter, $params) {
                $count = (int) $params['additional']['count'];
                $q->withCount(['orders as filtered_orders_count' => $orderFilter])
                ->having('filtered_orders_count', '=', $count);
            });
    }

    public function doesNotHaveOrder(Builder $query, $params, $except = null)
    {
        return $query->whereDoesntHave('orders', function ($query) use ($params, $except) {
            $query->where('status', '!=', OrderStatus::CANCELLED->value)
                ->when(isset($params['additional']['duration']) && $params['additional']['duration'] > 0, function ($q) use ($params) {
                    $q->where('created_at', '>=', Carbon::now()->subDays((int) $params['additional']['duration']));
                })
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })
                ->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        });
    }

    public function orderNotCompleted(Builder $query, $params, $except = null)
    {
        return $query->whereHas('orders', function ($query) use ($params, $except) {
            $query->where('status', '=', OrderStatus::CREATED->value)
                ->whereNull('service_finished_at')
                ->when(isset($params['additional']['duration']) && $params['additional']['duration'] > 0, function ($q) use ($params) {
                    $q->where('created_at', '>=', Carbon::now()->subDays((int) $params['additional']['duration']));
                })
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })
                ->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        });
    }

    public function moreThanOrderCount(Builder $query, $params, $except = null)
    {
        return $query->withCount(['orders as filtered_orders_count' => function ($q) use ($params, $except) {
            $q->where('status', '!=', OrderStatus::CANCELLED->value)
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })->when(isset($params['additional']['duration']) && $params['additional']['duration'] > 0, function ($q) use ($params) {
                    $q->where('created_at', '>=', Carbon::now()->subDays((int) $params['additional']['duration']));
                })->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        }])->having('filtered_orders_count', '>', (int) $params['additional']['count']);
    }

    public function lessThanOrderCount(Builder $query, $params, $except = null)
    {
        return $query->withCount(['orders as filtered_orders_count' => function ($q) use ($params, $except) {
            $q->where('status', '!=', OrderStatus::CANCELLED->value)
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })->when(isset($params['additional']['duration']) && $params['additional']['duration'] > 0, function ($q) use ($params) {
                    $q->where('created_at', '>=', Carbon::now()->subDays((int) $params['additional']['duration']));
                })->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        }])->having('filtered_orders_count', '<', (int) $params['additional']['count']);
    }

    public function orderPriceMoreThan(Builder $query, $params, $except = null)
    {
        return $query->whereHas('orders', function ($query) use ($params, $except) {
            $query->where('status', '!=', OrderStatus::CANCELLED->value)
                ->where('price', '>=', (float) $params['additional']['price'])
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })
                ->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        });
    }

    public function orderPriceLessThan(Builder $query, $params, $except = null)
    {
        return $query->whereHas('orders', function ($query) use ($params, $except) {
            $query->where('status', '!=', OrderStatus::CANCELLED->value)
                ->where('price', '<=', (float) $params['additional']['price'])
                ->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                })
                ->when($except, function ($q) use ($except) {
                    $q->where('id', '!=', $except);
                });
        });
    }

    public function orderRatedMoreThan(Builder $query, $params, $except = null)
    {
        return $query->whereHas('orders', function ($query) use ($params, $except) {
            $query->when($except, fn($q) => $q->where('id', $except));
            $query->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                });
            $query->whereHas('reviews', function ($q) use ($params) {
                $q->where('rating', '>', (int) $params['additional']['rating']);
            });
        });
    }

    public function orderRatedLessThan(Builder $query, $params, $except = null)
    {
        return $query->whereHas('orders', function ($query) use ($params, $except) {
            $query->when($except, fn($q) => $q->where('id', $except));
            $query->when($this->orderType($params), function ($q, string $orderType) {
                    $q->where('type', ucfirst($orderType));
                });
            $query->whereHas('reviews', function ($q) use ($params) {
                $q->where('rating', '<', (int) $params['additional']['rating']);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function orderType(array $params): ?string
    {
        $orderType = $params['additional']['order_type'] ?? $params['additional']['type'] ?? null;

        return in_array($orderType, [OrderType::HANDYMAN->value, OrderType::CLEANER->value], true) ? $orderType : null;
    }
}
