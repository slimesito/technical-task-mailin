<?php

it('renders the public tool pages without authentication', function (string $path, string $component) {
    $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->component($component));
})->with([
    'blacklist' => ['/blacklist', 'checks/blacklist'],
    'provider' => ['/provider', 'checks/provider'],
]);
