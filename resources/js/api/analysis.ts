import http from './http';

export type AnalysisSummary = {
    ventas: {
        rows: number;
        from: string | null;
        to: string | null;
    };
    ventadesg: {
        rows: number;
        from: string | null;
        to: string | null;
    };
};

export async function getAnalysisSummary() {
    const { data } = await http.get<AnalysisSummary>('/analysis/summary');
    return data;
}

export async function importAnalysisFile(type: 'ventas' | 'ventadesg', file: File) {
    const formData = new FormData();
    formData.append('type', type);
    formData.append('file', file, file.name);
    const { data } = await http.post<{ message: string }>('/analysis/import', formData);
    return data;
}

export type TopSellersResponse = {
    months: Array<{ key: string; label: string; iso: string }>;
    rows: Array<{
        provider_ident: string;
        provider_name: string;
        totals: Record<string, number>;
        grand_total: number;
    }>;
};

export async function getTopSellersMatrix() {
    const { data } = await http.get<TopSellersResponse>('/analysis/top-sellers');
    return data;
}

export type MonthDetailsResponse = {
    month: string;
    provider_ident: string;
    provider_name: string;
    items: Array<{
        producto_ident: string | null;
        producto_nombre: string | null;
        cantidad: number;
        total: number;
    }>;
    totals: {
        cantidad: number;
        monto: number;
    };
};

export async function getMonthDetails(params: { provider_ident: string; month: string }) {
    const { data } = await http.get<MonthDetailsResponse>('/analysis/month-details', { params });
    return data;
}

export type RecommendedImporteItem = {
    provider_ident: string;
    provider_name: string;
    current_importe: number;
    avg_monthly_sales: number;
    recommended_importe: number;
    months: number;
    total_sales: number;
    provider_email?: string | null;
    is_recommended: boolean;
};

export type RecommendedImporteResponse = {
    items: RecommendedImporteItem[];
    settings: {
        percentage: number;
        months: number;
        from: string;
        to: string;
    };
};

export async function getRecommendedImportes() {
    const { data } = await http.get<RecommendedImporteResponse>('/analysis/recommended-importes');
    return data;
}

export async function applyRecommendedImport(payload: {
    provider_ident: string;
    importe: number;
    accepted: boolean;
    send_email?: boolean;
    email?: string | null;
}) {
    const { data } = await http.post<{ message: string }>('/analysis/recommended-importes/apply', payload);
    return data;
}
