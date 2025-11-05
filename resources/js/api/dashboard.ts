// src/api/dashboard/mailer.ts
import http from './http' // adjust if your http client lives elsewhere

export type MailerTrackItem = {
    year: number
    month: number
    sent_count: number
    label: string      // e.g. "2025-10"
    date: string       // e.g. "2025-10-01"
}

export type MailerTrackResponse = {
    items: MailerTrackItem[]
    meta?: {
        count?: number
        order?: 'asc' | 'desc'
        limit_per_month?: number
    }
}

/**
 * GET /mailer-track (or /api/mailer-track if your API is prefixed)
 * Optional params: { limit, order }
 */
export async function listMailerTrack(
    params?: { limit?: number; order?: 'asc' | 'desc' }
): Promise<MailerTrackResponse> {
    const { data } = await http.get('/mailer-track', { params }) // change to '/api/mailer-track' if needed
    return normalizeMailerTrack(data)
}

/** Ensure label/date exist and are well-formed even if backend omits them */
function normalizeMailerTrack(raw: any): MailerTrackResponse {
    const rows: any[] = Array.isArray(raw?.items) ? raw.items : Array.isArray(raw) ? raw : []

    const items: MailerTrackItem[] = rows.map((r) => {
        const year = Number(r.year)
        const month = Number(r.month)
        const sent = Number(r.sent_count ?? r.sent ?? 0)
        const label = r.label ?? `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}`
        const date = r.date ?? `${label}-01`
        return { year, month, sent_count: sent, label, date }
    })

    return {
        items,
        meta: raw?.meta ?? {}
    }
}
