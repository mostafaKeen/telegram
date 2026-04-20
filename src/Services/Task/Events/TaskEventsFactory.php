<?php

/**
 * This file is part of the bitrix24-php-sdk package.
 *
 * © Vadim Soluyanov <vadimsallee@gmail.com>
 *
 * For the full copyright and license information, please view the MIT-LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Bitrix24\SDK\Services\Task\Events;

use Bitrix24\SDK\Core\Contracts\Events\EventInterface;
use Bitrix24\SDK\Core\Contracts\Events\EventsFabricInterface;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Services\Task\Events\OnTaskAdd\OnTaskAdd;
use Bitrix24\SDK\Services\Task\Events\OnTaskUpdate\OnTaskUpdate;
use Bitrix24\SDK\Services\Task\Events\OnTaskDelete\OnTaskDelete;
use Symfony\Component\HttpFoundation\Request;

readonly class TaskEventsFactory implements EventsFabricInterface
{
    #[\Override]
    public function isSupport(string $eventCode): bool
    {
        return in_array(strtoupper($eventCode), [
            OnTaskAdd::CODE,
            OnTaskUpdate::CODE,
            OnTaskDelete::CODE,
        ], true);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[\Override]
    public function create(Request $eventRequest): EventInterface
    {
        $eventPayload = $eventRequest->request->all();
        if (!array_key_exists('event', $eventPayload)) {
            throw new InvalidArgumentException('«event» key not found in event payload');
        }

        return match ($eventPayload['event']) {
            OnTaskAdd::CODE => new OnTaskAdd($eventRequest),
            OnTaskUpdate::CODE => new OnTaskUpdate($eventRequest),
            OnTaskDelete::CODE => new OnTaskDelete($eventRequest),
            default => throw new InvalidArgumentException(
                sprintf('Unexpected event code «%s»', $eventPayload['event'])
            ),
        };
    }
}
