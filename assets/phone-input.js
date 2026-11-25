function phoneDigits(value) {
    return value.replace(/\D/g, '').substring(0, 10);
}

function formatPhone(value) {
    const digits = phoneDigits(value);
    return digits.length === 10
        ? `(${digits.substring(0, 3)})${digits.substring(3, 6)}-${digits.substring(6)}`
        : digits;
}
