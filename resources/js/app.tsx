import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { Layout } from '@/components/Layout';
import { Spinner } from '@/components/ui';

import { LoginPage } from '@/pages/Login';
import { DashboardPage } from '@/pages/Dashboard';
import { DevicesPage } from '@/pages/Devices';
import { DeviceDetailPage } from '@/pages/DeviceDetail';
import { MembersPage } from '@/pages/Members';
import { MemberDetailPage } from '@/pages/MemberDetail';
import { AccessEventsPage } from '@/pages/AccessEvents';
import { BackupCodesPage } from '@/pages/BackupCodes';
import { SchedulesPage } from '@/pages/Schedules';
import { UsersPage } from '@/pages/Users';
import { WebhooksPage } from '@/pages/Webhooks';
import { AuditLogPage } from '@/pages/AuditLog';
import { SecurityPage } from '@/pages/Security';
import { NotFoundPage } from '@/pages/NotFound';

export function App() {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="flex h-full items-center justify-center">
                <Spinner label="Loading" />
            </div>
        );
    }

    if (!user) {
        return (
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                {/* Anything else while signed out lands on sign-in. Routes are
                    still declared below and guarded server-side regardless. */}
                <Route path="*" element={<Navigate to="/login" replace />} />
            </Routes>
        );
    }

    return (
        <Routes>
            <Route path="/login" element={<Navigate to="/" replace />} />

            <Route element={<Layout />}>
                <Route index element={<DashboardPage />} />
                <Route path="devices" element={<DevicesPage />} />
                <Route path="devices/:deviceId" element={<DeviceDetailPage />} />
                <Route path="members" element={<MembersPage />} />
                <Route path="members/:memberId" element={<MemberDetailPage />} />
                <Route path="events" element={<AccessEventsPage />} />
                <Route path="backup-codes" element={<BackupCodesPage />} />
                <Route path="schedules" element={<SchedulesPage />} />
                <Route path="users" element={<UsersPage />} />
                <Route path="webhooks" element={<WebhooksPage />} />
                <Route path="audit" element={<AuditLogPage />} />
                <Route path="security" element={<SecurityPage />} />
                <Route path="*" element={<NotFoundPage />} />
            </Route>
        </Routes>
    );
}
