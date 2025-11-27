import http from './http';
import type { RestockHorizon } from './reports';

export type SettingsResponse = {
    restock: {
        available: Array<RestockHorizon>;
        horizon: Array<RestockHorizon>;
        last_run?: string | null;
        include_zero?: boolean;
        min_days?: number;
        lookback_days?: number;
    };
    card_charge_percent: number;
    last_closing_balance: number | null;
    analysis?: {
        recommended_percentage: number;
        recommended_months: number;
    };
    history?: SettingHistoryEntry[];
    card_rebalance_history?: CardRebalanceLog[];
    card_rebalance_changes?: CardRebalanceChange[];
};

export type SettingHistoryEntry = {
    key: string;
    old_value: string | null;
    new_value: string | null;
    changed_by: number | null;
    changed_by_name: string | null;
    created_at: string;
};

export type CardRebalanceLog = {
    date_param: string;
    venta_id: number | null;
    sales_processed: number;
    sales_updated: number;
    lines_updated: number;
    sale_ids: string | null;
    message: string | null;
    triggered_by: number | null;
    triggered_by_name: string | null;
    created_at: string;
};

export type CardRebalanceChange = {
    venta_id: number;
    ventadesg_id: number;
    fecha_sale: string;
    public_total: number;
    total_venta: number;
    old_credit_card_discount: number;
    new_credit_card_discount: number;
    proveedor_id: number | null;
    created_at: string;
};

export async function getSystemSettings() {
    const { data } = await http.get<SettingsResponse>('/settings/general');
    return data;
}

export async function updateSystemSettings(payload: {
    horizon?: Array<RestockHorizon>;
    card_charge_percent?: number;
    restock_include_zero?: boolean;
    restock_min_days?: number;
    restock_lookback_days?: number;
    recommended_percentage?: number;
    recommended_months?: number;
}) {
    const { data } = await http.post<SettingsResponse>('/settings/general', payload);
    return data;
}

export async function runRestockForecastManual(horizon: Array<RestockHorizon>) {
    const { data } = await http.post<{ message: string; horizon: Array<RestockHorizon> }>(
        '/settings/general/run-restock',
        { horizon }
    );
    return data;
}

export async function runCashAutoClose() {
    const { data } = await http.post<{ message: string; dates: string[]; count: number }>(
        '/settings/general/run-cash-autoclose'
    );
    return data;
}

export type CardRebalanceRunResponse = {
    message: string;
    log?: string | null;
    stats?: {
        sales_processed: number;
        sales_updated: number;
        lines_updated: number;
        venta_id: number | null;
        date: string | null;
    } | null;
};

export async function runCardRebalance(payload: { date?: string; venta_id?: number }) {
    const { data } = await http.post<CardRebalanceRunResponse>(
        '/settings/general/run-card-rebalance',
        payload
    );
    return data;
}
