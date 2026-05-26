<?php
/**
 * AURA BUENOS AIRES - Hotel del Futuro
 * Diseño Ultra-Futurístico y Poderoso
 * Versión: 1.1 - Secure Edition
 */

session_start();

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Lógica de simulación de reserva
$reserva_confirmada = false;
$nombre = "";
$fecha = "";
$capsula = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservar'])) {
    // Validar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Error de validación de seguridad (CSRF).');
    }

    $reserva_confirmada = true;
    $nombre = htmlspecialchars($_POST['nombre'] ?? '');
    $fecha = htmlspecialchars($_POST['fecha'] ?? '');
    $capsula = htmlspecialchars($_POST['capsula'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA | Buenos Aires - Ultra-Futuristic Luxury Hotel</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --neon-cyan: #00f3ff;
            --neon-magenta: #ff00ff;
            --dark-bg: #050505;
            --glass: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        body {
            background-color: var(--dark-bg);
            color: #fff;
            font-family: 'Montserrat', sans-serif;
            overflow-x: hidden;
        }

        h1, h2, h3, .navbar-brand {
            font-family: 'Orbitron', sans-serif;
            text-transform: uppercase;
            letter-spacing: 4px;
        }

        /* --- Custom Scrollbar --- */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #000; }
        ::-webkit-scrollbar-thumb { background: var(--neon-cyan); box-shadow: 0 0 10px var(--neon-cyan); }

        /* --- Background Effects --- */
        .bg-grid {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image:
                linear-gradient(var(--glass-border) 1px, transparent 1px),
                linear-gradient(90deg, var(--glass-border) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -2;
            opacity: 0.2;
        }

        .glow-sphere {
            position: fixed;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(0, 243, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            z-index: -1;
            pointer-events: none;
            filter: blur(80px);
        }

        /* --- Navbar --- */
        .navbar {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            padding: 20px 0;
            transition: 0.5s;
        }
        .navbar-brand { font-weight: 900; color: var(--neon-cyan) !important; text-shadow: 0 0 10px var(--neon-cyan); }
        .nav-link { color: #fff !important; font-weight: 600; margin: 0 15px; position: relative; }
        .nav-link::after {
            content: ''; position: absolute; bottom: -5px; left: 0; width: 0; height: 2px;
            background: var(--neon-magenta); transition: 0.3s;
        }
        .nav-link:hover::after { width: 100%; }

        /* --- Hero Section --- */
        .hero {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            text-align: center;
            background: linear-gradient(rgba(5, 5, 5, 0.7), rgba(5, 5, 5, 0.7)), url('https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&q=80&w=1920');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .hero-content h1 {
            font-size: clamp(3rem, 10vw, 8rem);
            font-weight: 900;
            background: linear-gradient(to right, #fff, var(--neon-cyan), var(--neon-magenta));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }

        .hero-tagline { font-size: 1.5rem; letter-spacing: 10px; color: var(--neon-cyan); text-shadow: 0 0 10px var(--neon-cyan); }

        /* --- Glass Cards --- */
        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 40px;
            transition: 0.4s;
            position: relative;
            overflow: hidden;
        }
        .glass-card:hover {
            border-color: var(--neon-cyan);
            transform: translateY(-10px);
            box-shadow: 0 0 30px rgba(0, 243, 255, 0.2);
        }
        .glass-card::before {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: conic-gradient(transparent, transparent, transparent, var(--neon-cyan));
            animation: rotate 4s linear infinite;
            z-index: -1;
            opacity: 0; transition: 0.5s;
        }
        .glass-card:hover::before { opacity: 0.2; }

        @keyframes rotate { 100% { transform: rotate(360deg); } }

        /* --- Sections --- */
        section { padding: 120px 0; }
        .section-title { font-size: 3.5rem; margin-bottom: 60px; position: relative; }
        .section-title::before {
            content: 'FUTURE'; position: absolute; top: -30px; left: 0; font-size: 1rem; color: var(--neon-magenta); letter-spacing: 10px;
        }

        /* --- Rooms Section --- */
        .room-img {
            width: 100%; height: 400px; object-fit: cover; border-radius: 15px; margin-bottom: 25px;
            filter: grayscale(0.5) brightness(0.7); transition: 0.5s;
        }
        .glass-card:hover .room-img { filter: grayscale(0) brightness(1); }

        /* --- Booking Form --- */
        .booking-section { background: linear-gradient(to bottom, transparent, #0a0a0a); }
        .form-control {
            background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: #fff;
            padding: 15px; border-radius: 10px;
        }
        .form-control:focus {
            background: rgba(255,255,255,0.1); border-color: var(--neon-cyan); box-shadow: 0 0 15px var(--neon-cyan); color: #fff;
        }

        .btn-neon {
            background: transparent; border: 2px solid var(--neon-cyan); color: var(--neon-cyan);
            padding: 15px 40px; font-family: 'Orbitron', sans-serif; font-weight: 700;
            position: relative; overflow: hidden; transition: 0.3s;
        }
        .btn-neon:hover {
            background: var(--neon-cyan); color: #000; box-shadow: 0 0 40px var(--neon-cyan);
        }

        /* --- Footer --- */
        footer { border-top: 1px solid var(--glass-border); padding: 50px 0; text-align: center; }

        /* --- Responsive --- */
        @media (max-width: 768px) {
            .hero-content h1 { font-size: 4rem; }
            .section-title { font-size: 2.5rem; }
        }
    </style>
</head>
<body>

    <div class="bg-grid"></div>
    <div class="glow-sphere" id="sphere1" style="top: 10%; right: -10%;"></div>
    <div class="glow-sphere" id="sphere2" style="bottom: 10%; left: -10%; background: radial-gradient(circle, rgba(255, 0, 255, 0.05) 0%, transparent 70%);"></div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">AURA <small style="font-size: 10px;">BA</small></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#booking">RESERVAR</a></li>
                    <li class="nav-item"><a class="nav-link" href="#suites">SUITES</a></li>
                    <li class="nav-item"><a class="nav-link" href="#servicios">SERVICIOS</a></li>
                    <li class="nav-item"><a class="nav-link" href="#booking">CONTACTO</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="hero">
        <div class="container hero-content">
            <div class="reveal">
                <p class="hero-tagline">BUENOS AIRES 2077</p>
                <h1>ELITE LUXURY</h1>
                <p class="mt-4 opacity-75">Bienvenidos al hotel más avanzado del planeta. <br> Donde la tecnología cuántica se encuentra con el confort absoluto.</p>
                <div class="mt-5">
                    <a href="#booking" class="btn-neon">INGRESAR AL FUTURO</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Suites Section -->
    <section id="suites">
        <div class="container">
            <h2 class="section-title">SUITES CUÁNTICAS</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="glass-card">
                        <img src="https://images.unsplash.com/photo-1631679706909-1844bbd07221?auto=format&fit=crop&q=80&w=800" alt="Suite" class="room-img">
                        <h3>NEON LOFT</h3>
                        <p class="opacity-50">Vista panorámica a la Puerto Madero del futuro con cristales de opacidad inteligente.</p>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <span class="text-white fw-bold">Ξ 2.5 / Noche</span>
                            <i class="fa-solid fa-arrow-right-long text-info"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-card">
                        <img src="https://images.unsplash.com/photo-1574362848149-11496d93a7c7?auto=format&fit=crop&q=80&w=800" alt="Suite" class="room-img">
                        <h3>CYBER SUITE</h3>
                        <p class="opacity-50">Inmersión sensorial completa y asistentes holográficos personales 24/7.</p>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <span class="text-white fw-bold">Ξ 4.8 / Noche</span>
                            <i class="fa-solid fa-arrow-right-long text-info"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-card">
                        <img src="https://images.unsplash.com/photo-1615529328331-f8917597711f?auto=format&fit=crop&q=80&w=800" alt="Suite" class="room-img">
                        <h3>AURA ZENITH</h3>
                        <p class="opacity-50">El pináculo del lujo. Gravedad cero opcional y bio-hacking de descanso.</p>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <span class="text-white fw-bold">Ξ 12.0 / Noche</span>
                            <i class="fa-solid fa-arrow-right-long text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="servicios">
        <div class="container">
            <h2 class="section-title">SERVICIOS DE ÉLITE</h2>
            <div class="row g-5">
                <div class="col-lg-6">
                    <div class="glass-card p-0 overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1540555700478-4be289fbecee?auto=format&fit=crop&q=80&w=1200" class="img-fluid" alt="Quantum Spa">
                        <div class="p-4">
                            <h3>QUANTUM SPA</h3>
                            <p>Regeneración celular acelerada y relajación profunda en cámaras de aislamiento sensorial.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="glass-card p-0 overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1550966841-39f4f866b334?auto=format&fit=crop&q=80&w=1200" class="img-fluid" alt="Gastronomía">
                        <div class="p-4">
                            <h3>GASTRONOMÍA MOLECULAR</h3>
                            <p>Sabores del mañana diseñados por IA y ejecutados por chefs galardonados.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="glass-card p-0 overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1614850523296-d8c1af93d400?auto=format&fit=crop&q=80&w=1600" class="img-fluid" style="height: 400px; width:100%; object-fit: cover;" alt="VR Lounge">
                        <div class="p-4">
                            <h3>VR LOUNGE & METAVERSO</h3>
                            <p>Conexión directa a los nodos más exclusivos del metaverso global desde la comodidad de AURA.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Info Section -->
    <section class="booking-section" id="booking">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-5 mb-lg-0">
                    <h2 class="section-title">ASEGURA TU LUGAR</h2>
                    <p class="mb-5 lead">La demanda por la experiencia AURA es global. <br> Reserva tu cápsula de lujo hoy mismo.</p>

                    <div class="d-flex align-items-center mb-4">
                        <div class="btn-neon me-3" style="padding: 10px; border-radius: 50%"><i class="fa-solid fa-shield-halved"></i></div>
                        <div>
                            <h5 class="mb-0">Protocolos de Seguridad Neural</h5>
                            <p class="small opacity-50 mb-0">Protección total de datos biométricos.</p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="btn-neon me-3" style="padding: 10px; border-radius: 50%; border-color: var(--neon-magenta); color: var(--neon-magenta);"><i class="fa-solid fa-bolt"></i></div>
                        <div>
                            <h5 class="mb-0">Check-in Instantáneo</h5>
                            <p class="small opacity-50 mb-0">Reconocimiento facial y acceso sin contacto.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="glass-card">
                        <?php if ($reserva_confirmada): ?>
                            <div class="text-center py-5">
                                <i class="fa-solid fa-circle-check fa-5x text-info mb-4"></i>
                                <h2 class="text-white">RESERVA EXITOSA</h2>
                                <p>Bienvenido al futuro, <?php echo $nombre; ?>.</p>
                                <p class="small opacity-50">Cápsula: <?php echo $capsula; ?> | Fecha: <?php echo $fecha; ?></p>
                                <a href="index.php" class="btn btn-outline-info mt-3">Volver</a>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="mb-4">
                                    <label class="form-label text-info">IDENTIDAD</label>
                                    <input type="text" name="nombre" class="form-control" placeholder="Nombre completo" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label text-info">CRONO-DESTINO</label>
                                    <input type="date" name="fecha" class="form-control" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label text-info">CÁPSULA SELECCIONADA</label>
                                    <select name="capsula" class="form-control">
                                        <option>Neon Loft</option>
                                        <option>Cyber Suite</option>
                                        <option>Aura Zenith</option>
                                    </select>
                                </div>
                                <button type="submit" name="reservar" class="btn-neon w-100">INICIAR PROTOCOLO DE RESERVA</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <p class="mb-0 opacity-50">&copy; <?php echo date('Y'); ?> AURA BUENOS AIRES. THE FUTURE IS NOW.</p>
            <div class="mt-3">
                <i class="fa-brands fa-instagram mx-2 text-info"></i>
                <i class="fa-brands fa-twitter mx-2 text-info"></i>
                <i class="fa-brands fa-discord mx-2 text-info"></i>
            </div>
        </div>
    </footer>

    <!-- GSAP & Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>

    <script>
        // Animaciones iniciales
        gsap.from(".hero-tagline", { opacity: 0, y: 30, duration: 1, delay: 0.5 });
        gsap.from(".hero-content h1", { opacity: 0, scale: 0.8, duration: 1.5, delay: 0.8, ease: "expo.out" });
        gsap.from(".hero-content p", { opacity: 0, y: 20, duration: 1, delay: 1.2 });
        gsap.from(".btn-neon", { opacity: 0, y: 20, duration: 1, delay: 1.5 });

        // Animación de scroll para las tarjetas
        gsap.registerPlugin(ScrollTrigger);

        gsap.from("#suites .glass-card", {
            scrollTrigger: {
                trigger: "#suites",
                start: "top 80%",
            },
            opacity: 0,
            y: 50,
            duration: 1,
            stagger: 0.3
        });

        gsap.from("#servicios .glass-card", {
            scrollTrigger: {
                trigger: "#servicios",
                start: "top 80%",
            },
            opacity: 0,
            scale: 0.9,
            duration: 1.2,
            stagger: 0.2
        });

        gsap.from(".section-title", {
            scrollTrigger: {
                trigger: ".section-title",
                start: "top 90%",
            },
            opacity: 0,
            x: -50,
            duration: 1
        });

        // Movimiento sutil de las esferas de luz
        document.addEventListener('mousemove', (e) => {
            const mouseX = e.clientX;
            const mouseY = e.clientY;

            gsap.to("#sphere1", {
                x: (mouseX - window.innerWidth / 2) * 0.05,
                y: (mouseY - window.innerHeight / 2) * 0.05,
                duration: 2,
                ease: "power2.out"
            });

            gsap.to("#sphere2", {
                x: (mouseX - window.innerWidth / 2) * -0.03,
                y: (mouseY - window.innerHeight / 2) * -0.03,
                duration: 2,
                ease: "power2.out"
            });
        });
    </script>
</body>
</html>
