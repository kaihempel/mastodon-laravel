<?php

namespace KaiHempel\Mastodon\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KaiHempel\Mastodon\Auth\AuthorizationFlow;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\Models\MastodonAccount;

/**
 * Optional controller for the connect flow. Disable via
 * mastodon.routes.enabled and use AuthorizationFlow directly to replace it.
 */
class OAuthController extends Controller
{
    public function connect(Request $request, AuthorizationFlow $flow): RedirectResponse
    {
        $data = $request->validate(['instance' => ['required', 'string', 'max:255']]);

        try {
            $authorization = $flow->start($data['instance']);
        } catch (MastodonException $e) {
            return back()->withErrors(['instance' => $e->getMessage()])->withInput();
        }

        return redirect()->away($authorization->url);
    }

    public function callback(Request $request, AuthorizationFlow $flow): RedirectResponse
    {
        $redirect = redirect()->to((string) config('mastodon.routes.redirect_after', '/'));

        try {
            $account = $flow->complete(
                $request->query('state'),
                $request->query('code'),
                $request->user(),
                $request->query('error'),
                $request->query('error_description'),
            );
        } catch (MastodonException $e) {
            return $redirect->with('mastodon.error', $e->getMessage());
        }

        return $redirect->with('mastodon.connected', $account->handle());
    }

    public function disconnect(Request $request, AuthorizationFlow $flow, MastodonAccount $account): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null
            && $account->owner_type === $user->getMorphClass()
            && (string) $account->owner_id === (string) $user->getKey(),
            403,
        );

        try {
            $flow->disconnect($account);
        } catch (MastodonException $e) {
            return back()->with('mastodon.error', $e->getMessage());
        }

        return back()->with('mastodon.disconnected', $account->handle());
    }
}
