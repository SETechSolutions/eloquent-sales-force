<?php

namespace Lester\EloquentSalesForce\Http\Controllers;

use Illuminate\Routing\Controller;
use Lester\EloquentSalesForce\Facades\SObjects;

class LoginController extends Controller
{
    /**
     * Send the user to Salesforce to authorise the connected app.
     *
     * @return mixed
     */
    public function redirect()
    {
        return SObjects::authorize();
    }

    /**
     * Handle the OAuth callback and store the tokens.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback()
    {
        SObjects::callback();

        return redirect(config('eloquent_sf.redirectTo') ?: config('eloquent_sf.routes.login.redirectTo', '/'));
    }
}
