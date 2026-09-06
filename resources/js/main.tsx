import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from '@/lib/auth';
import { App } from '@/app';
import '../css/app.css';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            // Door state changes constantly; a stale-but-instant render
            // followed by a quiet refetch is the right default here.
            staleTime: 15_000,
            retry: (failureCount, error) => {
                const status = (error as { status?: number }).status;

                // Retrying an authorisation failure only produces more of them.
                if (status === 401 || status === 403 || status === 422) {
                    return false;
                }

                return failureCount < 2;
            },
            refetchOnWindowFocus: true,
        },
    },
});

const container = document.getElementById('root');

if (!container) {
    throw new Error('The dashboard root element is missing from the document.');
}

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </BrowserRouter>
        </QueryClientProvider>
    </StrictMode>,
);
