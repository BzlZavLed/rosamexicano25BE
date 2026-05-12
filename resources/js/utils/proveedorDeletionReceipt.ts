import { jsPDF } from 'jspdf';
import type { ProveedorDeletionReceipt } from '../api/proveedores';

export type DeletionReceiptPdf = {
    base64: string;
    filename: string;
};

function formatCurrency(amount: number, currencyCode = 'MXN', locale = 'es-MX') {
    if (!Number.isFinite(amount)) return '$0.00';
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency: currencyCode }).format(amount);
    } catch {
        return `$${amount.toFixed(2)}`;
    }
}

function formatDateTimeLabel(value?: string | null) {
    if (!value) return 'Fecha no disponible';
    const normalized = value.includes('T') ? value : value.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function slugify(value: string) {
    return (value || 'proveedor')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60) || 'proveedor';
}

export function openPdfInNewTab(resource: string) {
    if (!resource || typeof window === 'undefined') return false;

    const isDataUri = resource.startsWith('data:');
    const maybeBase64 = isDataUri ? resource.split('base64,')[1] ?? '' : resource.trim();
    let url = resource;
    let revoke: (() => void) | undefined;

    if (!isDataUri) {
        try {
            const binary = atob(maybeBase64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i += 1) {
                bytes[i] = binary.charCodeAt(i);
            }
            url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
            revoke = () => URL.revokeObjectURL(url);
        } catch {
            url = `data:application/pdf;base64,${maybeBase64}`;
        }
    }

    try {
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch {
        if (revoke) {
            window.setTimeout(revoke, 60_000);
        }
        return false;
    }

    if (revoke) {
        window.setTimeout(revoke, 60_000);
    }

    return true;
}

export function buildProveedorDeletionReceiptPdf(receipt: ProveedorDeletionReceipt): DeletionReceiptPdf {
    const doc = new jsPDF({ unit: 'mm', format: 'letter' });
    const marginX = 16;
    const pageHeight = doc.internal.pageSize.getHeight();
    const usableWidth = 184;
    let y = 18;

    const provider = receipt.proveedor;
    const products = Array.isArray(receipt.products) ? receipt.products : [];
    const productsQuantity = Number(receipt.products_quantity ?? products.reduce((sum, product) => {
        const qty = Number(product.cantidad ?? product.existencia ?? 0);
        return sum + (Number.isFinite(qty) ? qty : 0);
    }, 0));

    const addPageIfNeeded = (needed = 12) => {
        if (y + needed <= pageHeight - 18) return;
        doc.addPage();
        y = 18;
    };

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text('Rosa Mexicano', marginX, y);
    y += 8;
    doc.setFontSize(14);
    doc.text('Aviso de baja de proveedor', marginX, y);
    y += 8;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text(`Fecha de baja: ${formatDateTimeLabel(receipt.deleted_at ?? provider.deleted_at)}`, marginX, y);
    y += 6;
    doc.text(`Proveedor: ${provider.nombre}`, marginX, y);
    y += 6;
    doc.text(`Identificador: ${provider.ident}`, marginX, y);
    y += 6;
    if (provider.email) {
        doc.text(`Correo: ${provider.email}`, marginX, y);
        y += 6;
    }
    if (provider.tel) {
        doc.text(`Telefono: ${provider.tel}`, marginX, y);
        y += 6;
    }

    y += 4;
    doc.setFont('helvetica', 'bold');
    doc.text('Motivo de baja', marginX, y);
    y += 6;
    doc.setFont('helvetica', 'normal');
    const reasonLines = doc.splitTextToSize(receipt.delete_reason || provider.delete_reason || 'Sin motivo registrado.', usableWidth);
    doc.text(reasonLines, marginX, y);
    y += reasonLines.length * 5 + 6;

    doc.setFont('helvetica', 'bold');
    doc.text(`Productos dados de baja: ${receipt.products_count}`, marginX, y);
    if (Number.isFinite(productsQuantity)) {
        doc.text(`Piezas en inventario: ${productsQuantity}`, marginX + 82, y);
    }
    y += 8;

    if (!products.length) {
        doc.setFont('helvetica', 'normal');
        doc.text('No se encontraron productos activos asociados al proveedor.', marginX, y);
        y += 6;
    } else {
        doc.setFontSize(9);
        doc.setFont('helvetica', 'bold');
        doc.text('SKU', marginX, y);
        doc.text('Cant.', marginX + 28, y);
        doc.text('Producto / descripcion', marginX + 48, y);
        doc.text('Precio venta', marginX + 148, y);
        y += 5;
        doc.setDrawColor(210);
        doc.line(marginX, y, marginX + usableWidth, y);
        y += 5;

        doc.setFont('helvetica', 'normal');
        products.forEach((product) => {
            const name = product.nombre || 'Producto sin nombre';
            const description = product.descripcion || 'Sin descripcion';
            const quantity = Number(product.cantidad ?? product.existencia ?? 0);
            const nameLines = doc.splitTextToSize(String(name), 92);
            const descriptionLines = doc.splitTextToSize(`Descripcion: ${description}`, 92);
            const rowHeight = Math.max(10, nameLines.length * 4.5 + descriptionLines.length * 4 + 3);
            addPageIfNeeded(rowHeight + 4);
            doc.text(String(product.ident ?? '--'), marginX, y);
            doc.text(Number.isFinite(quantity) ? String(quantity) : '0', marginX + 28, y);
            doc.text(nameLines, marginX + 48, y);
            doc.text(formatCurrency(Number(product.precio ?? 0)), marginX + 148, y);
            doc.setFontSize(8);
            doc.setTextColor(90);
            doc.text(descriptionLines, marginX + 48, y + nameLines.length * 4.5);
            doc.setTextColor(0);
            doc.setFontSize(9);
            y += rowHeight;
        });
    }

    addPageIfNeeded(24);
    y += 8;
    doc.setFontSize(9);
    doc.setFont('helvetica', 'italic');
    doc.text('Este comprobante confirma la baja del proveedor y de sus productos activos en el sistema.', marginX, y);

    const dataUri = doc.output('datauristring');
    const base64 = (dataUri.split(',')[1] ?? '').trim();
    const dateStamp = new Date().toISOString().slice(0, 10);
    const filename = `baja-proveedor-${slugify(provider.nombre)}-${dateStamp}.pdf`;
    return { base64, filename };
}
