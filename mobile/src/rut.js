/*
 * RUT chileno en el teléfono.
 *
 * Está también en el servidor (`app/Support/Rut.php`) y las dos copias tienen
 * que decir lo mismo. No es duplicación por descuido: validar aquí evita que el
 * vendedor escriba una ficha completa sin señal, la guarde en la bandeja de
 * salida y se entere tres horas después, cuando vuelve la red, de que el
 * dígito verificador estaba malo. El servidor valida igual, porque el teléfono
 * no es de fiar.
 */
export const Rut = {
    /** Deja solo dígitos y K. */
    limpiar(rut) {
        return String(rut ?? '').toUpperCase().replace(/[^0-9K]/g, '');
    },

    /** Cuerpo sin dígito verificador: es el `CodAux` del cliente en Softland. */
    cuerpo(rut) {
        const r = this.limpiar(rut);
        return r.length >= 2 ? r.slice(0, -1) : r;
    },

    /** Dígito verificador de un cuerpo, módulo 11. */
    dv(cuerpo) {
        let suma = 0;
        let mult = 2;
        for (let i = cuerpo.length - 1; i >= 0; i--) {
            suma += Number(cuerpo[i]) * mult;
            mult = mult < 7 ? mult + 1 : 2;
        }
        const esperado = 11 - (suma % 11);
        return esperado === 11 ? '0' : esperado === 10 ? 'K' : String(esperado);
    },

    esValido(rut) {
        const r = this.limpiar(rut);
        if (r.length < 2) return false;
        const cuerpo = r.slice(0, -1);
        return /^\d+$/.test(cuerpo) && this.dv(cuerpo) === r.slice(-1);
    },

    /** 12.345.678-5 */
    formatear(rut) {
        const r = this.limpiar(rut);
        if (r.length < 2) return r;
        return Number(r.slice(0, -1)).toLocaleString('es-CL') + '-' + r.slice(-1);
    },
};
