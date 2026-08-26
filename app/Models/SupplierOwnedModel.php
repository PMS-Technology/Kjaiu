<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

abstract class SupplierOwnedModel extends Model
{
    protected static function booted(): void
    {
        static::saving(function (SupplierOwnedModel $model): void {
            $accountId = $model->getAttribute('supplier_account_id');
            if (! is_int($accountId) && ! (is_string($accountId) && ctype_digit($accountId))) {
                throw new DomainException('A persisted supplier account is required.');
            }
            if (! SupplierAccount::query()->whereKey($accountId)->exists()) {
                throw new DomainException('The supplier account does not exist.');
            }

            foreach ($model->supplierOwnedRelations() as $relation => $foreignKey) {
                $relatedId = $model->getAttribute($foreignKey);
                if ($relatedId === null) {
                    continue;
                }

                $related = $model->{$relation}()->first();
                if ($related === null) {
                    throw new DomainException('The related supplier record does not exist.');
                }
                if ((string) $related->getAttribute('supplier_account_id') !== (string) $accountId) {
                    throw new DomainException('Cross-account supplier references are not allowed.');
                }
            }
        });
    }

    protected function supplierOwnedRelations(): array
    {
        return [];
    }

    protected static function requirePersisted(Model $model, string $label): Model
    {
        if (! $model->exists
            || $model->getKey() === null
            || ($persisted = $model->newQuery()->find($model->getKey())) === null) {
            throw new DomainException("A persisted {$label} is required.");
        }

        return $persisted;
    }
}
