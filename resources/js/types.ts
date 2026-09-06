/**
 * Shapes returned by /api/admin/v1.
 *
 * Kept hand-written rather than generated: the API resources are small and
 * stable, and a hand-written type is where a field's meaning can be written
 * down next to it.
 */

export type AccessMethod =
    | 'fingerprint'
    | 'otp'
    | 'backup_code'
    | 'admin_auth'
    | 'remote';

export type AccessResult = 'granted' | 'denied';

export type DeviceStatus = 'provisioned' | 'active' | 'suspended' | 'retired';

export type MemberStatus = 'active' | 'suspended';

export type EnrollmentStatus = 'pending' | 'active' | 'revoked' | 'orphaned';

export interface Tenant {
    id: string;
    name: string;
    slug: string;
    status: string;
    settings: Record<string, unknown>;
    devices_count?: number;
    members_count?: number;
    users_count?: number;
    created_at: string | null;
}

export interface User {
    id: string;
    name: string;
    email: string;
    status: string;
    roles?: string[];
    permissions?: string[];
    tenant?: Tenant;
    is_super_admin: boolean;
    two_factor_enabled: boolean;
    two_factor_required: boolean;
    last_login_at: string | null;
    created_at: string | null;
}

export interface Device {
    id: string;
    /** The device-asserted identifier, e.g. SMA_4821. */
    device_id: string;
    name: string;
    location: string | null;
    /** Administrative state — distinct from whether it is currently answering. */
    status: DeviceStatus;
    /** Whether it is inside its health-poll window right now. */
    is_online: boolean;
    firmware_version: string | null;
    last_seen_at: string | null;
    last_health_at: string | null;
    template_capacity: number;
    enrolled_count: number;
    metadata: Record<string, unknown> | null;
    active_backup_code_set?: BackupCodeSet | null;
    pending_commands?: DeviceCommand[];
    credentials?: DeviceCredential[];
    created_at: string | null;
}

export interface DeviceCredential {
    id: string;
    api_key: string;
    secret_last4: string | null;
    label: string | null;
    last_used_at: string | null;
    last_used_ip: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    is_usable: boolean;
    created_at: string | null;
}

export interface Member {
    id: string;
    full_name: string;
    phone: string | null;
    email: string | null;
    status: MemberStatus;
    /** Grants the panel's Admin Settings flow. Not an access method. */
    is_admin: boolean;
    notes: string | null;
    enrollments?: Enrollment[];
    enrollments_count?: number;
    schedules?: AccessSchedule[];
    created_at: string | null;
    updated_at: string | null;
}

export interface Enrollment {
    id: string;
    status: EnrollmentStatus;
    /** Device-local sensor slot. Null for a phone-only member. */
    fingerprint_slot: number | null;
    enrolled_at: string | null;
    revoked_at: string | null;
    last_reconciled_at: string | null;
    device?: Device;
    member?: Member;
}

export interface AccessEvent {
    id: string;
    method: AccessMethod;
    method_label: string;
    result: AccessResult;
    reason: string;
    reason_label: string;
    /** Server-assigned on receipt. The device has no trustworthy clock. */
    occurred_at: string;
    device_reported_at: string | null;
    fingerprint_slot: number | null;
    confidence: number | null;
    metadata: Record<string, unknown> | null;
    device?: {
        id: string;
        device_id: string;
        name: string;
        location: string | null;
    };
    member?: { id: string; full_name: string } | null;
    actor?: { id: string; name: string } | null;
}

export interface DeviceCommand {
    id: string;
    type: string;
    status: 'pending' | 'sent' | 'acked' | 'failed' | 'expired';
    payload: Record<string, unknown> | null;
    result: Record<string, unknown> | null;
    sent_at: string | null;
    acked_at: string | null;
    expires_at: string | null;
    issued_by?: string | null;
    created_at: string | null;
}

export interface BackupCode {
    id: string;
    last4: string;
    used_at: string | null;
}

export interface BackupCodeSet {
    id: string;
    status: 'active' | 'superseded';
    reason: string;
    issued_at: string;
    superseded_at: string | null;
    fetched_at: string | null;
    issued_by?: string | null;
    codes?: BackupCode[];
}

export interface OtpRequest {
    id: string;
    /** Masked. The full number is not readable from an audit screen. */
    phone: string;
    status: string;
    attempts: number;
    max_attempts: number;
    expires_at: string;
    verified_at: string | null;
    delivery: Record<string, { status?: string; error?: string }> | null;
    device?: { id: string; name: string };
    member?: { id: string; full_name: string } | null;
    created_at: string | null;
}

export interface AuditLog {
    id: string;
    action: string;
    subject_type: string | null;
    subject_id: number | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
    user?: { id: string; name: string; email: string } | null;
    created_at: string | null;
}

export interface ScheduleWindow {
    id: number;
    /** ISO-8601 weekday: 1 = Monday .. 7 = Sunday. */
    weekday: number;
    starts_at: string;
    ends_at: string;
}

export interface AccessSchedule {
    id: string;
    name: string;
    timezone: string;
    is_active: boolean;
    windows?: ScheduleWindow[];
    members_count?: number;
    device_id?: number | null;
}

export interface WebhookEndpoint {
    id: string;
    url: string;
    description: string | null;
    events: string[];
    is_active: boolean;
    last_delivered_at: string | null;
    consecutive_failures: number;
    created_at: string | null;
}

export interface DashboardSummary {
    devices: {
        total: number;
        online: number;
        offline: number;
        active: number;
        suspended: number;
    };
    members: { total: number; active: number; admins: number };
    today: {
        total: number;
        granted: number;
        denied: number;
        denied_rate: number;
    };
    trend: { date: string; granted: number; denied: number }[];
    top_denial_reasons: { reason: string; label: string; count: number }[];
    attention: {
        devices_offline: number;
        orphaned_enrollments: number;
        pending_commands: number;
    };
    recent_events: { data: AccessEvent[] } | AccessEvent[];
}

/** Laravel's offset paginator. */
export interface Paginated<T> {
    data: T[];
    links: { first: string; last: string; prev: string | null; next: string | null };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
    };
}

/** Laravel's cursor paginator — used for the access log, which is unbounded. */
export interface CursorPaginated<T> {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: {
        path: string;
        per_page: number;
        next_cursor: string | null;
        prev_cursor: string | null;
    };
}
