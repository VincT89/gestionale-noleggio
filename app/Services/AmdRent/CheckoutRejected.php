<?php

namespace App\Services\AmdRent;

/** Stripe rejected creation before a checkout session could be opened. */
class CheckoutRejected extends \RuntimeException {}
