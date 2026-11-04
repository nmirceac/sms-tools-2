<?php

namespace SmsTools2\Enums;

/** Message status, as reported by the platform (API v2 "status" field). */
enum MessageStatus: string
{
    /** Saved locally, not yet accepted by the platform. */
    case Pending = 'pending';
    /** Accepted by the platform and handed to the gateway. */
    case Submitted = 'submitted';
    /** Accepted by the mobile operator. */
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Rejected = 'rejected';
    /** Never sent: it stayed queued longer than the application's max message age. */
    case Expired = 'expired';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Rejected, self::Expired], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::Failed, self::Rejected, self::Expired], true);
    }

    /**
     * Status only moves forward; a late "delivered" wins over a failure
     * (same rules as the platform).
     */
    public function canTransitionTo(self $next): bool
    {
        if ($next === $this || $this === self::Expired) {
            return false;
        }

        if ($next === self::Delivered) {
            return true;
        }

        if ($this->isFinal()) {
            return false;
        }

        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Submitted => 1,
            self::Sent => 2,
            default => 3,
        };
    }
}
