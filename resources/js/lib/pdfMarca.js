/**
 * Marca del sistema (SmartMotos) para todos los documentos PDF.
 * El logo vive en resources/images/logo-smartmotos.jpg y Vite lo inlined en
 * base64 con «?inline», así el PDF no depende de la red al generarse.
 * Proporción del logo: 1225 × 230 px  →  ancho / 5.33 = alto.
 */
import logoBase64 from '../images/logo-smartmotos.jpg?inline';

export const MARCA = { data: logoBase64, formato: 'JPEG' };

/** Alto del logo para un ancho dado (proporción fija). */
export const altoMarca = (ancho) => ancho / 5.33;

/** Dibuja el logo en (x, y) y devuelve su alto en mm. */
export function dibujarMarca(doc, x, y, ancho = 46) {
    const alto = altoMarca(ancho);
    doc.addImage(MARCA.data, MARCA.formato, x, y, ancho, alto);
    return alto;
}

/** Logo centrado en la página (tickets de 80 mm y comprobantes). */
export function dibujarMarcaCentrada(doc, centroX, y, ancho = 50) {
    return dibujarMarca(doc, centroX - ancho / 2, y, ancho);
}
