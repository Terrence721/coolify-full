<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Team|null $team
 * @property int $id
 * @property int $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $stripe_invoice_paid
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property bool $stripe_cancel_at_period_end
 * @property string|null $stripe_plan_id
 * @property string|null $stripe_feedback
 * @property string|null $stripe_comment
 * @property bool $stripe_trial_already_ended
 * @property bool $stripe_past_due
 * @property Carbon|null $stripe_refunded_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeCancelAtPeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeFeedback($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeInvoicePaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripePastDue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripePlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeRefundedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStripeTrialAlreadyEnded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Subscription extends Model
{
    protected $fillable = [
        'team_id',
        'stripe_invoice_paid',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_cancel_at_period_end',
        'stripe_plan_id',
        'stripe_feedback',
        'stripe_comment',
        'stripe_trial_already_ended',
        'stripe_past_due',
        'stripe_refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'stripe_refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function billingInterval(): string
    {
        if ($this->stripe_plan_id) {
            $configKey = collect(config('subscription'))
                ->search($this->stripe_plan_id);

            if ($configKey && str($configKey)->contains('yearly')) {
                return 'yearly';
            }
        }

        return 'monthly';
    }

    public function type()
    {
        if (isStripe()) {
            if (! $this->stripe_plan_id) {
                return 'zero';
            }
            $subscription = Subscription::where('id', $this->id)->first();
            if (! $subscription) {
                return null;
            }
            $subscriptionPlanId = data_get($subscription, 'stripe_plan_id');
            if (! $subscriptionPlanId) {
                return null;
            }
            $subscriptionInvoicePaid = data_get($subscription, 'stripe_invoice_paid');
            if (! $subscriptionInvoicePaid) {
                return null;
            }
            $subscriptionConfigs = collect(config('subscription'));
            $stripePlanId = null;
            $subscriptionConfigs->map(function ($value, $key) use ($subscriptionPlanId, &$stripePlanId) {
                if ($value === $subscriptionPlanId) {
                    $stripePlanId = $key;
                }
            })->first();
            if ($stripePlanId) {
                return str($stripePlanId)->after('stripe_price_id_')->before('_')->lower();
            }
        }

        return 'zero';
    }
}
