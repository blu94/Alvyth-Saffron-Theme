<?php

namespace Theme\Components;

use App\Repositories\Setting\Payment\PaymentInterface;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\View;

/**
 * Pay now, or pay when it arrives.
 *
 * Cash on delivery is the payment a food shop expects and no gateway models: the order is
 * accepted at `payment_status = unpaid` and settled on handover (spec §10, §14 item 7,
 * register O8). Core decides whether it is offered and what happens when it is chosen; this
 * component is only the control, and it exists because a customer cannot choose a payment
 * method that is never drawn.
 *
 * **Renders nothing when there is nothing to choose.** A shop with a gateway and no cash
 * option, or cash and no gateway, has one answer — and an answer belongs in a hidden input,
 * not a picker with a single option. That is the same rule the ordering-mode tiles follow,
 * and the reason a shop that has never heard of this feature sees no change at all.
 *
 * The value rides on `[data-checkout-payment]`, core's own collector, which reads it exactly
 * as it reads `[data-checkout-mode]` — first element wins, radios only when checked. It is a
 * first-class field rather than a `[data-checkout-field]`, because it decides whether a gateway
 * session is opened, and the plugin bag is for values only the listener that asked for them
 * understands.
 */
class PaymentChoice
{
    public function render(array $data, string $locale, string $themeViewPath): string
    {
        try {
            $settings = app(PaymentInterface::class)->getSettings();
        } catch (\Throwable $e) {
            report($e);

            return '';
        }

        $cod = filter_var($settings['cod_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        // Asked of the factory rather than of the settings: a gateway named but missing its
        // credentials cannot take money, and offering it would send the customer to a 503 they
        // can do nothing about.
        $online = PaymentGatewayFactory::isConfigured($settings);

        $methods = array_values(array_filter([
            $online ? 'online' : null,
            $cod ? 'cod' : null,
        ]));

        // Nothing configured at all: say nothing and let core's own message stand. A control
        // here would be a choice between no options.
        if ($methods === []) {
            return '';
        }

        return View::make($themeViewPath, [
            'methods'      => $methods,
            'codLabel'     => $this->translate($settings['cod_label'] ?? '', $locale) ?: __('Cash on delivery'),
            'instructions' => $this->translate($settings['cod_instructions'] ?? '', $locale),
            'uid'          => 'saffron-payment-choice',
        ])->render();
    }

    /** A settings value that may be a plain string or a locale map. */
    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : ''));
        }

        return (string) ($value ?? '');
    }
}
