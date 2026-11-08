<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The app's user model, as the engine sees it: any Authenticatable Eloquent
 * model, named by `custom-reports.user_model` (the auth provider's model
 * when unset). Owners, viewers and authors are stored by its id.
 */
final class Users
{
    public static function id(Authenticatable $user): int
    {
        return (int) $user->getAuthIdentifier();
    }

    /**
     * @return class-string<Model>
     */
    public static function model(): string
    {
        $model = config('custom-reports.user_model') ?? config('auth.providers.users.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new LogicException('Set custom-reports.user_model to the app\'s Eloquent user model.');
        }

        return $model;
    }
}
