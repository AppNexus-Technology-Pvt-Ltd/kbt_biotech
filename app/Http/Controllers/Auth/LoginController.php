<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Support\Home;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class LoginController extends Controller
{
    public function __construct(private AuthService $auth, private OtpService $otp) {}

    public function show(Request $request): View
    {
        $request->session()->forget('otp.pending'); // "Back" from OTP cancels the half-finished login

        return view('auth.login');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'login.required' => 'Enter your username or email.',
            'password.required' => 'Enter your password.',
        ]);

        $result = $this->auth->attempt($data['login'], $data['password'], $request);

        if ($result['status'] !== AuthService::OK) {
            [$message, $code] = $this->failure($result);

            return $this->fail($request, $message, $code);
        }

        $user = $result['user'];
        $remember = $request->boolean('remember');

        if ($this->auth->otpRequired($user)) {
            $request->session()->regenerate(); // new id before storing pending-login state
            $request->session()->put('otp.pending', [
                'user_id' => $user->id,
                'remember' => $remember,
                'expires_at' => now()->addSeconds(config('kbt.otp.pending_login_seconds'))->timestamp,
            ]);
            $this->otp->issue($user);
            $url = route('otp.show');

            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Verification code sent.', 'data' => ['redirect' => $url, 'otp_required' => true]])
                : redirect($url);
        }

        $this->auth->completeLogin($user, $remember, $request);
        $url = redirect()->intended(Home::urlFor($user))->getTargetUrl();

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Signed in.', 'data' => ['redirect' => $url, 'otp_required' => false]])
            : redirect($url);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $this->auth->logout($request);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Signed out.'])
            : redirect()->route('login')->with('status', 'You have been signed out.');
    }

    /** @return array{0:string,1:int} */
    private function failure(array $result): array
    {
        return match ($result['status']) {
            AuthService::THROTTLED => ['Too many sign-in attempts. Try again in '.$this->wait($result['retry_after']).'.', 429],
            AuthService::LOCKED => ['This account is temporarily locked. Try again in '.$this->wait($result['retry_after']).' or reset your password.', 423],
            AuthService::DISABLED => ['This account has been disabled. Contact your administrator.', 403],
            AuthService::INACTIVE => ['This account is inactive. Contact your administrator.', 403],
            default => ['Invalid username/email or password.', 401],
        };
    }

    private function wait(int $seconds): string
    {
        return $seconds >= 60 ? ceil($seconds / 60).' minute(s)' : max(1, $seconds).' second(s)';
    }

    private function fail(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        return back()->withInput($request->only('login', 'remember'))->withErrors(['credentials' => $message]);
    }
}
