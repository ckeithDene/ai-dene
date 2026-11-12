<?php

namespace App\Http\Middleware;

use App\Enums\PageAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePageAccess
{
    /**
     * Handle an incoming request.
     *
     * Any listed page is enough. A route such as the company sales strategy
     * stays open when the person can view either list that links to it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$pages): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $this->allows($user, $pages), 403);

        return $next($request);
    }

    /**
     * @param  array<int, string>  $pages
     */
    private function allows(User $user, array $pages): bool
    {
        foreach ($pages as $page) {
            $access = PageAccess::tryFrom($page);

            if ($access instanceof PageAccess && $user->canViewPage($access)) {
                return true;
            }
        }

        return false;
    }
}
