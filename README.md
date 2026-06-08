<div align="center">

# 🥗 Proyecto de Dietista

### _Tu nutrición, organizada._

![PHP](https://img.shields.io/badge/PHP-mysqli-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-BD-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JS-Vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![Responsive](https://img.shields.io/badge/📱-Responsive-2F9E73?style=for-the-badge)
![Auth](https://img.shields.io/badge/Auth-bcrypt%20%2B%20CSRF-2F9E73?style=for-the-badge&logo=letsencrypt&logoColor=white)

</div>

---

## 👋 Hola, soy Álvaro

Este es **mi proyecto personal**: una aplicación web de seguimiento nutricional que he construido desde cero para conectar a **clientes**, **dietistas** y **administradores** alrededor de un mismo plan.

Lo creé en parte para uso propio, y me ha servido para conseguir **objetivos reales**:

> 💪 **Bajar 15 kilos**, reducir mi **porcentaje de grasa del 18% al 11%** y, en definitiva, **ser más saludable**.

La idea era tener en un solo sitio la dieta, el progreso (peso, medidas, fotos), los objetivos y el contacto directo con el dietista. Y eso es justo lo que hace esta app. 🙌

> [!NOTE]
> 📱 **La aplicación está totalmente adaptada a móviles y tablets** (diseño _responsive_). Se ve y se usa igual de bien desde el ordenador que desde el teléfono.

---

## 📑 Índice

1. [¿Qué es?](#-qué-es)
2. [Primeros pasos · Instalación](#-primeros-pasos--instalación)
3. [Los 3 roles del sistema](#-los-3-roles-del-sistema)
4. [Diseño responsive](#-diseño-responsive)
5. [Estructura del proyecto](#-estructura-del-proyecto)
6. [Esquema de la base de datos](#-esquema-de-la-base-de-datos)
7. [Seguridad](#-seguridad)

---

## 🌱 ¿Qué es?

Es una plataforma de **seguimiento nutricional** pensada para que un profesional y sus clientes trabajen juntos. Cada persona entra con su cuenta y, según su **rol**, ve unas herramientas u otras. Todo gira en torno a un núcleo común: la **dieta**, el **progreso** del cliente y la **comunicación** entre ambos.

|   | Funcionalidad | Descripción |
|---|---------------|-------------|
| 🍽️ | **Dietas a medida** | Planes con bloques de comidas, macros objetivo y lista de la compra automática. |
| 📈 | **Progreso real** | Peso, % de grasa, medidas, fotos y check-in semanal de bienestar. |
| 🔮 | **Predicciones** | Cálculo de TDEE y proyección de peso a partir del calendario de dietas. |
| 💬 | **Chat directo** | Mensajería entre cliente, dietista y soporte (admin). |

> **Stack:** PHP (mysqli) · MySQL / MariaDB · HTML + CSS + JS vanilla _(sin frameworks)_ · Sesiones + bcrypt · Zona horaria `Europe/Madrid`.

---

## 🚀 Primeros pasos · Instalación

Pon en marcha el proyecto en tu servidor local (XAMPP, MAMP, Laragon…) en **cuatro pasos**. Lo más importante de todo es <ins>montar la base de datos</ins> y crear el primer usuario administrador.

### 1️⃣ Coloca los archivos

Copia todo el proyecto dentro de la carpeta pública de tu servidor (por ejemplo `htdocs/` en XAMPP) y arranca **Apache** y **MySQL**.

### 2️⃣ Importa la base de datos

> ⭐ **Lo primero de todo es montar la base de datos.**

Abre **phpMyAdmin** e importa el archivo **`dieta.sql`**. Ese archivo crea la base de datos y **todas las tablas** necesarias.

> [!NOTE]
> La app se conecta a una base de datos llamada **`dietista`** con usuario `root` y contraseña `rootroot` (ver `includes/conexion.php`).
> Si tu MySQL usa otros datos, **edita ese archivo antes de continuar**.

### 3️⃣ Crea el usuario administrador

La base de datos viene **vacía de usuarios**, así que hay que crear el primer **admin** a mano. Abre la pestaña **SQL** de phpMyAdmin (con la base de datos seleccionada) y ejecuta esto:

```sql
INSERT INTO `usuarios` (`rol`, `usuario`, `email`, `password`, `nombre_completo`, `activo`, `tema`) 
VALUES ('admin', 'admin', 'admin@github.com', '$2y$10$abcdefghijklmnopqrstuu03XQq4uczTxEDPe3ML4iARROHzHJ0n6', 'Administrador Principal', 1, 'light');

-- Usuario: admin
-- Contraseña: 1234
```

> [!IMPORTANT]
> Esto crea la cuenta con &nbsp; 👤 **Usuario:** `admin` &nbsp;·&nbsp; 🔑 **Contraseña:** `1234`
> La contraseña va **cifrada con bcrypt**, por eso ves ese texto tan largo en vez de "1234".
> 👉 <ins>Cámbiala desde **Mi perfil** nada más entrar.</ins>

### 4️⃣ Entra y crea al resto del equipo

Abre `login.php` en el navegador e inicia sesión como **admin**. Desde ahí:

- En **👥 Usuarios** → das de alta a los **dietistas** y a los **clientes**.
- En **🔗 Asignaciones** → vinculas cada cliente con su dietista.

---

## 👤 Los 3 roles del sistema

Cuando alguien inicia sesión, la app mira su rol y le muestra un **menú y unas funciones distintas**. Estos son los tres perfiles y todo lo que puede hacer cada uno.

<table>
<tr>
<th>🧑 Cliente</th>
<th>👩‍⚕️ Dietista</th>
<th>🛡️ Administrador</th>
</tr>
<tr valign="top">
<td>Sigue la dieta y registra su progreso</td>
<td>Diseña dietas y hace el seguimiento</td>
<td>Gestiona toda la plataforma</td>
</tr>
</table>

<br>

<details open>
<summary><h3>🧑 &nbsp;Cliente &nbsp;— <em>la persona que sigue la dieta</em></h3></summary>

| Sección | Qué puede hacer |
|---|---|
| 🏠 **Inicio** | Panel con su predicción de peso (semana/mes), calendario del mes, dieta de hoy, próxima consulta y última medida. |
| 🍽️ **Mi dieta** | Ver la dieta de cada día con sus comidas y macros (kcal, proteínas, carbos, grasas). Imprimir / guardar en PDF. |
| 🛒 **Lista de la compra** | Genera automáticamente la lista de alimentos a partir de su dieta. |
| 📝 **Check-in semanal** | Valora hambre, energía, sueño, cumplimiento y ánimo → puntuación de bienestar. |
| 📏 **Medidas** | Registra medidas corporales (cintura, cadera, pecho…) y ve su evolución. |
| 📈 **Progresos** | Apunta peso, % de grasa y pasos, con gráfica e histórico completo. |
| 📸 **Fotos** | Sube fotos frontales, de perfil y de espalda para documentar cambios. |
| 🎯 **Objetivos** | Crea metas (peso, % grasa, medida o personalizadas) con barra de progreso y **fecha estimada** de cumplimiento. |
| 📅 **Consultas** | Ve sus citas programadas con el dietista. |
| 💬 **Chat** | Habla con su dietista (o con el admin para soporte). |
| 👤 **Mi perfil** | Rellena su cuestionario (anamnesis): edad, altura, actividad, alergias… y elige tema claro/oscuro. |

> 🔒 **Permisos:** <ins>solo ve y edita sus propios datos.</ins> No puede acceder a información de otros usuarios.

#### 📸 Capturas · Cliente

<p align="center">
  <img src="NOCOPIAR/captura%20de%20inicio%20en%20cliente.png" width="540" alt="Inicio del cliente">
  <br><em>🏠 Inicio del cliente con predicciones y calendario</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20mi%20dieta%20en%20cliente.png" width="540" alt="Mi dieta del cliente">
  <br><em>🍽️ Mi dieta: comidas y macros del día</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20alternativas%20de%20alimentos%20en%20mi%20dieta%20de%20cliente.png" width="540" alt="Alternativas de alimentos">
  <br><em>🔄 Alternativas de alimentos dentro de la dieta</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20lista%20de%20la%20compra%20en%20cliente.png" width="540" alt="Lista de la compra">
  <br><em>🛒 Lista de la compra generada automáticamente</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20evolucion%20de%20peso%20en%20cliente.png" width="540" alt="Evolución de peso">
  <br><em>📈 Evolución del peso del cliente</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20imprimir%20dieta%20cliente.png" width="540" alt="Imprimir dieta">
  <br><em>🖨️ Vista de imprimir / PDF de la dieta</em>
</p>

</details>

<details>
<summary><h3>👩‍⚕️ &nbsp;Dietista &nbsp;— <em>el profesional que crea y supervisa</em></h3></summary>

| Sección | Qué puede hacer |
|---|---|
| 🏠 **Inicio** | Resumen de su actividad y de sus clientes. |
| 👥 **Mis clientes** | Ficha completa: anamnesis, cálculo de **TDEE**, estrategia activa, adherencia y dietas seguidas. |
| 🍽️ **Dietas** | Crea y edita dietas con bloques de comidas y macros objetivo. Asigna a **un cliente o a varios a la vez (en lote)**. |
| 📑 **Plantillas** | Guarda dietas base reutilizables para no empezar desde cero. |
| 🥦 **Alimentos** | Crea, edita y borra sus propios alimentos con valores nutricionales por ración. |
| 📆 **Calendario** | Asigna qué dieta toca cada día a cada cliente. |
| 📏 **Medidas** | Consulta las medidas corporales de sus clientes. |
| 📸 **Comparador de fotos** | Compara fotos **antes / después** lado a lado. |
| 📝 **Check-ins** | Revisa los check-in semanales de bienestar de cada cliente. |
| 📅 **Consultas** | Programa citas, registra asistencia, deja notas y define el plan siguiente y la próxima cita. |
| 🎯 **Objetivos** | Gestiona los objetivos de sus clientes. |
| 📄 **Reporte PDF** | Genera un informe por rango de fechas: anamnesis, pesos, medidas, check-ins, objetivos, consultas y una nota personal. |
| 📌 **Notas** | Anotaciones **privadas** sobre cada cliente (no las ve el cliente). |
| 💬 **Chat / 🔔 Notificaciones** | Habla con sus clientes y recibe avisos del sistema. |
| 👤 **Mi perfil** | Edita sus datos, contraseña y tema. |

> 🔒 **Permisos:** <ins>solo trabaja con los clientes que tiene **asignados**.</ins> No puede tocar clientes de otros dietistas.

#### 📸 Capturas · Dietista

<p align="center">
  <img src="NOCOPIAR/captura%20de%20mis%20clientes%20en%20dietista.png" width="540" alt="Mis clientes">
  <br><em>👥 Listado y fichas de los clientes</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20creador%20de%20dietas%20en%20dietista.png" width="540" alt="Creador de dietas">
  <br><em>🍽️ Creador de dietas con bloques de comidas y macros</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20alimentos%20en%20dietista.png" width="540" alt="Alimentos">
  <br><em>🥦 Gestión de alimentos del dietista</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20calendario%20de%20cliente%20en%20dietista.png" width="540" alt="Calendario de cliente">
  <br><em>📆 Calendario: qué dieta toca cada día</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20notas%20en%20dietista.png" width="540" alt="Notas">
  <br><em>📌 Notas privadas sobre el cliente</em>
</p>

<p align="center">
  <img src="NOCOPIAR/captura%20de%20reporte%20hecho%20por%20el%20dietista.png" width="540" alt="Reporte del dietista">
  <br><em>📄 Reporte de evolución generado por el dietista</em>
</p>

</details>

<details>
<summary><h3>🛡️ &nbsp;Administrador &nbsp;— <em>el responsable de la plataforma</em></h3></summary>

| Sección | Qué puede hacer |
|---|---|
| 🏠 **Inicio (Dashboard)** | KPIs globales: usuarios, dietas, alimentos, consultas, check-ins, mensajes, tamaño de la BD, top de dietistas, clientes sin asignar y actividad reciente. |
| 👥 **Usuarios** | Crea, edita y borra **cualquier** usuario. Activa/desactiva cuentas y hace un **"wipe"** para reiniciar los datos de un cliente conservando la cuenta. |
| 🥦 **Alimentos** | Gestión global de la base de alimentos (puede aprobar alimentos para todos). |
| 🔗 **Asignaciones** | Vincula clientes con su dietista, individual o **en lote**, con filtros y buscador. |
| 🖼️ **Banners** | Publica mensajes/avisos que ven todos los usuarios al iniciar sesión. |
| 📜 **Logs** | Registro de todas las acciones importantes de los administradores. |
| 🔔 **Notificaciones** | Envía avisos a un usuario concreto o a grupos enteros. |
| 💾 **Copia de seguridad** | Descarga toda la base de datos en un `.sql` con un solo clic. |
| 💬 **Chat** | Puede hablar con cualquier usuario para dar soporte. |
| 👤 **Mi perfil** | Edita sus datos, contraseña y tema. |

> 🔒 **Permisos:** <ins>acceso total.</ins> Es el **único rol** que puede dar de alta a dietistas y clientes y enlazarlos entre sí.

#### 📸 Capturas · Administrador

<p align="center">
  <img src="NOCOPIAR/captura%20de%20inicio%20en%20administrador.png" width="540" alt="Inicio del administrador">
  <br><em>🏠 Dashboard del administrador con los KPIs globales</em>
</p>

</details>

---

## 📱 Diseño responsive

Toda la interfaz está **adaptada a móviles y tablets**: el menú se convierte en un cajón lateral, las tarjetas se reorganizan en una columna y los gráficos se ajustan al ancho de la pantalla. Puedes usar la app cómodamente desde el teléfono en el día a día.

<p align="center">
  <img src="NOCOPIAR/captura%20desde%20movil%20en%20inicio%20cliente.png" width="260" alt="Vista móvil del inicio del cliente">
  <br><em>📱 Inicio del cliente visto desde el móvil</em>
</p>

---

## 🗂️ Estructura del proyecto

El proyecto está organizado **por roles**: cada uno tiene su carpeta en `/roles`, y las piezas comunes viven en `/includes`.

```text
📁 dietista/
├── index.html            # Página de bienvenida (landing)
├── login.php             # Inicio de sesión (acepta usuario o email)
├── logout.php            # Cerrar sesión
├── mensajes.php          # Chat (con endpoint AJAX)
├── dieta.sql             # ⭐ Base de datos a importar
│
├── 📁 includes/          # Piezas compartidas
│   ├── conexion.php      # Conexión BD + sesión + funciones helper
│   ├── sidebar.php       # Cabecera, menú lateral y banners
│   ├── footer.php        # Cierre del HTML
│   └── predicciones.php  # Cálculo de TDEE y proyección de peso
│
├── 📁 roles/
│   ├── 📁 cliente/        # Pantallas del cliente
│   ├── 📁 dietista/       # Pantallas del dietista
│   └── 📁 admin/          # Pantallas del administrador
│
├── 📁 css/  style.css     # Estilos (responsive, con tema claro/oscuro)
├── 📁 js/   sidebar.js    # Menú, notificaciones y cambio de tema
├── 📁 ajax/               # Endpoints para peticiones en segundo plano
└── 📁 uploads/fotos/      # Fotos subidas por los clientes
```

> [!TIP]
> Cada pantalla incluye `includes/conexion.php` al principio (para tener BD y sesión) y llama a `requiere_rol()` para asegurarse de que **solo entra quien debe**. Después incluye `sidebar.php` arriba y `footer.php` abajo para mantener siempre el mismo marco visual.

---

## 🗃️ Esquema de la base de datos

Estas son las tablas principales que crea `dieta.sql`. Todo gira alrededor de **`usuarios`**, que guarda a los tres roles en una sola tabla.

| Tabla | Para qué sirve |
|---|---|
| 👥 `usuarios` | Todas las cuentas (admin, dietista, cliente), rol, login, tema y a qué dietista pertenece cada cliente. |
| 🥦 `alimentos` | Alimentos con sus valores nutricionales por ración (kcal, proteínas, carbos, grasas). |
| 🍽️ `dietas_base` | Las dietas creadas, con macros objetivo, color, icono y cliente asociado. |
| 🧱 `comidas_bloques` | Los bloques de comida (desayuno, comida…) dentro de cada dieta. |
| 🔗 `dieta_alimentos` | Qué alimentos y cantidades hay en cada bloque. |
| 📆 `calendario_asignaciones` | Qué dieta toca cada día para cada cliente. |
| 📋 `fichas_anamnesis` | Cuestionario del cliente: edad, altura, actividad, estrategia y macros objetivo. |
| 🗂️ `historial_estrategias` | Histórico de estrategias nutricionales aplicadas. |
| ⚖️ `progresos_metricas` | Registros de peso, % de grasa y pasos en el tiempo. |
| 📏 `medidas_corporales` | Medidas (cintura, cadera, pecho…) por fecha. |
| 📝 `checkins_semanales` | Valoración semanal de hambre, energía, sueño, dieta y ánimo. |
| 🎯 `objetivos` | Metas del cliente con su estado y fechas. |
| 📅 `consultas` | Citas entre dietista y cliente, con notas y plan siguiente. |
| 📸 `archivos_boveda` | Fotos de progreso (frontal, perfil, espalda). |
| 💬 `chats_mensajes` | Mensajes del chat entre usuarios. |
| 🔔 `notificaciones` | Avisos dirigidos a cada usuario. |
| 📢 `banners_sistema` | Mensajes globales que publica el admin. |
| 📜 `logs_admin` | Registro de acciones de los administradores. |

---

## 🔐 Seguridad

El proyecto incluye varias **buenas prácticas de seguridad** de serie:

- 🔐 **Contraseñas con bcrypt** — se guardan cifradas con `password_hash()` y se comprueban con `password_verify()`. <ins>Nunca en texto plano.</ins>
- 🛡️ **Consultas preparadas** — todas las queries usan _prepared statements_ de mysqli → evita la **inyección SQL**.
- 🧼 **Escapado de salida** — la función `e()` escapa el HTML antes de mostrarlo → protege frente a **XSS**.
- 🎫 **Token CSRF** — cada formulario lleva un token validado con `csrf_check()` al enviarlo.
- 🚪 **Control de acceso por rol** — `requiere_rol()` bloquea páginas que no corresponden al rol del usuario.
- 🍪 **Sesiones seguras** — cookies _HttpOnly_, _SameSite_ y _Secure_ (en HTTPS), con regeneración de ID al iniciar sesión.

---

<div align="center">

### 🥗 Proyecto de Dietista

_Un proyecto personal de **Álvaro** · Tu nutrición, organizada._

**⚠️ Recuerda cambiar la contraseña del `admin` tras el primer acceso.**

</div>
