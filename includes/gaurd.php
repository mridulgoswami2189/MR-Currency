<?php
// ...inside mrwcmc_guard_strip_currency_param() just before wp_safe_redirect():
if (headers_sent()) {
    // Bail gracefully: do not try to redirect/send cookies if output already started
    return;
}
