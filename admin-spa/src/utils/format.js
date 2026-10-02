/**
 * Shared formatting helpers for the administrator SPA.
 *
 * The platform stores money as decimal strings and every timestamp as a unix
 * integer in seconds; the API expects the same representation back, so the
 * date-picker helpers always produce/consume seconds.
 */

const pad = (value) => String(value).padStart(2, '0');

/** Render a unix-seconds timestamp as `YYYY-MM-DD HH:mm:ss`. */
export const formatTime = (value, withTime = true) => {
    const seconds = Number(value);

    if (!seconds || Number.isNaN(seconds)) {
        return '';
    }

    const date = new Date(seconds * 1000);
    const day = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    if (!withTime) {
        return day;
    }

    return `${day} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
};

/** Render a money-ish value with two decimals, tolerating null/undefined. */
export const formatMoney = (value) => {
    const number = Number(value);

    return Number.isNaN(number) ? '0.00' : number.toFixed(2);
};

/** Convert an `el-date-picker` value (ms timestamp or seconds) to seconds. */
export const toSeconds = (value) => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const number = Number(value);

    if (Number.isNaN(number)) {
        return '';
    }

    // Anything that looks like a millisecond timestamp is normalised, mirroring
    // the original request layer.
    return number > 1e12 ? Math.floor(number / 1000) : Math.floor(number);
};

/** Normalise a `[start, end]` range picker value into two second timestamps. */
export const rangeToSeconds = (range) => {
    if (!Array.isArray(range) || range.length !== 2) {
        return ['', ''];
    }

    return [toSeconds(range[0]), toSeconds(range[1])];
};

/** Extract the pagination envelope the API returns for every list endpoint. */
export const pickList = (payload) => {
    const data = payload || {};

    return {
        list: data.list || [],
        total: Number(data.total || 0),
        page: Number(data.page || 1),
        limit: Number(data.limit || 20),
        total_page: Number(data.total_page || 1),
    };
};

/** Element Plus tag type for a host/invoice/order status keyword. */
export const statusTagType = (status) => {
    switch (String(status)) {
        case 'Active':
        case 'Paid':
        case 'Completed':
            return 'success';
        case 'Suspended':
        case 'Unpaid':
        case 'Pending':
            return 'warning';
        case 'Cancelled':
        case 'Deleted':
        case 'Fraud':
        case 'Refunded':
            return 'danger';
        default:
            return 'info';
    }
};
