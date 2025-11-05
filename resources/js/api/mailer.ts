import http from './http';

export type MailerEntry = {
    id: number;
    mail: string;
    asunto: string;
    mensaje: string;
    status: number;
    fecha: string;
    email?: string | null;
};

type ListParams = {
    search?: string;
    page?: number;
    per_page?: number;
    sort?: string;
};

export async function listMailerEntries(params?: ListParams) {
    const { data } = await http.get('/mailer', { params });
    return data as {
        data?: MailerEntry[];
        meta?: {
            current_page?: number;
            per_page?: number;
            total?: number;
            last_page?: number;
        };
        total?: number;
        current_page?: number;
        per_page?: number;
        last_page?: number;
    } | MailerEntry[];
}

export async function resendMailerEmail(payload: {
    email: string;
    subject?: string | null;
    body?: string | null;
    pdf?: string | null;
    url?: string | null;
}) {
    const body: Record<string, any> = { email: payload.email };
    if (payload.subject) body.subject = payload.subject;
    if (payload.body) body.body = payload.body;
    if (payload.pdf) body.pdf = payload.pdf;
    if (payload.url) body.url = payload.url;
    const { data } = await http.post('/mailer/resend', body);
    return data;
}
