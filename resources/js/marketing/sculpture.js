import * as THREE from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

class VoiceLoop extends THREE.Curve {
    getPoint(t, point = new THREE.Vector3()) {
        const angle = t * Math.PI * 2;
        const radius = 1.54 + 0.12 * Math.cos(angle * 5);
        return point.set(
            Math.cos(angle) * radius,
            Math.sin(angle) * radius,
            0.19 * Math.sin(angle * 3),
        );
    }
}

export function createSculpture(container) {
    if (!container) return;
    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({
            alpha: true,
            antialias: true,
            powerPreference: 'low-power',
        });
    } catch {
        return;
    }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    renderer.setClearColor(0x000000, 0);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.12;
    container.appendChild(renderer.domElement);
    renderer.domElement.setAttribute('aria-hidden', 'true');
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(36, 1, 0.1, 60);
    camera.position.set(0, 0.05, 8.8);
    const pmrem = new THREE.PMREMGenerator(renderer);
    const room = new RoomEnvironment();
    const environment = pmrem.fromScene(room, 0.04);
    scene.environment = environment.texture;
    scene.environmentIntensity = 1.1;
    room.dispose();
    pmrem.dispose();
    const key = new THREE.DirectionalLight('#fff5ff', 3.1);
    key.position.set(-3, 5, 5);
    scene.add(key);
    const rim = new THREE.DirectionalLight('#d6beff', 2.8);
    rim.position.set(4, -1, 3);
    scene.add(rim);
    const fill = new THREE.DirectionalLight('#f6ffce', 0.75);
    fill.position.set(-4, -3, 1);
    scene.add(fill);
    const sculpture = new THREE.Group();
    sculpture.position.set(0, 0.12, 0);
    sculpture.rotation.set(0.22, -0.37, -0.25);
    scene.add(sculpture);

    const grape = new THREE.MeshPhysicalMaterial({
        color: '#7120d3',
        metalness: 0.32,
        roughness: 0.16,
        clearcoat: 1,
        clearcoatRoughness: 0.06,
        iridescence: 0.18,
        iridescenceIOR: 1.4,
        iridescenceThicknessRange: [120, 350],
        envMapIntensity: 1.4,
    });
    const lime = new THREE.MeshPhysicalMaterial({
        color: '#d7ff00',
        metalness: 0.13,
        roughness: 0.17,
        clearcoat: 1,
        clearcoatRoughness: 0.07,
        envMapIntensity: 1.2,
    });
    const glass = new THREE.MeshPhysicalMaterial({
        color: '#d9c6ff',
        metalness: 0.25,
        roughness: 0.09,
        transmission: 0.28,
        thickness: 0.65,
        ior: 1.45,
        clearcoat: 1,
        envMapIntensity: 1.6,
    });
    const loop = new THREE.Mesh(new THREE.TubeGeometry(new VoiceLoop(), 220, 0.6, 32, true), grape);
    sculpture.add(loop);
    const core = new THREE.Mesh(new THREE.SphereGeometry(0.83, 48, 32), lime);
    core.position.set(0, 0, 0.17);
    sculpture.add(core);
    const satellite = new THREE.Mesh(new THREE.SphereGeometry(0.29, 32, 24), glass);
    satellite.position.set(1.5, 1.85, 0.28);
    scene.add(satellite);
    const tiny = new THREE.Mesh(new THREE.SphereGeometry(0.14, 24, 16), lime);
    tiny.position.set(-1.93, -0.98, 0.6);
    scene.add(tiny);
    const wire = new THREE.Mesh(
        new THREE.TorusGeometry(2.02, 0.014, 8, 160),
        new THREE.MeshStandardMaterial({
            color: '#b793e0',
            metalness: 0.5,
            roughness: 0.2,
            transparent: true,
            opacity: 0.45,
        }),
    );
    wire.rotation.set(1.07, -0.2, -0.32);
    sculpture.add(wire);

    const shadowCanvas = document.createElement('canvas');
    shadowCanvas.width = 128;
    shadowCanvas.height = 64;
    const ctx = shadowCanvas.getContext('2d');
    if (ctx) {
        const gradient = ctx.createRadialGradient(64, 32, 0, 64, 32, 64);
        gradient.addColorStop(0, 'rgba(93,35,149,.24)');
        gradient.addColorStop(1, 'rgba(93,35,149,0)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, 128, 64);
        const shadow = new THREE.Mesh(
            new THREE.PlaneGeometry(4.9, 1.25),
            new THREE.MeshBasicMaterial({
                map: new THREE.CanvasTexture(shadowCanvas),
                transparent: true,
                depthWrite: false,
            }),
        );
        shadow.position.set(0.1, -2.15, -1.4);
        scene.add(shadow);
    }
    let frame = null;
    let visible = true;
    let disposed = false;
    let lost = false;
    let paused = document.documentElement.dataset.motion === 'reduced';
    let pointerX = 0,
        pointerY = 0;
    let tiltX = 0,
        tiltY = 0;
    const start = performance.now();
    function draw(now) {
        frame = null;
        if (disposed || lost) return;
        const time = (now - start) / 1000;
        tiltX += (pointerX - tiltX) * 0.035;
        tiltY += (pointerY - tiltY) * 0.035;
        if (!paused) {
            sculpture.rotation.y = -0.37 + Math.sin(time * 0.27) * 0.12 + tiltX * 0.22;
            sculpture.rotation.x = 0.22 + Math.cos(time * 0.31) * 0.045 + tiltY * 0.12;
            sculpture.rotation.z = -0.25 + Math.sin(time * 0.19) * 0.055;
            sculpture.position.y = 0.12 + Math.sin(time * 0.7) * 0.07;
            satellite.position.y = 1.85 + Math.sin(time * 0.8 + 0.7) * 0.09;
            tiny.position.y = -0.98 + Math.cos(time * 0.65) * 0.07;
        }
        renderer.render(scene, camera);
        container.classList.add('has-webgl');
        if (!paused && visible && !document.hidden) frame = requestAnimationFrame(draw);
    }
    function schedule() {
        if (frame === null && !disposed && !lost && visible && !document.hidden)
            frame = requestAnimationFrame(draw);
    }
    const resize = new ResizeObserver(() => {
        const { width, height } = container.getBoundingClientRect();
        if (!width || !height || disposed) return;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        schedule();
    });
    resize.observe(container);
    const visibility = new IntersectionObserver(
        ([entry]) => {
            visible = entry.isIntersecting;
            if (!visible && frame !== null) {
                cancelAnimationFrame(frame);
                frame = null;
            }
            if (visible) schedule();
        },
        { rootMargin: '80px' },
    );
    visibility.observe(container);
    const onPointer = (event) => {
        if (!paused && event.pointerType !== 'touch') {
            pointerX = event.clientX / window.innerWidth - 0.5;
            pointerY = event.clientY / window.innerHeight - 0.5;
        }
    };
    const onMotion = (event) => {
        paused = event.detail.paused;
        if (paused && frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }
        schedule();
    };
    const onVisibility = () => {
        if (document.hidden && frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }
        if (!document.hidden) schedule();
    };
    const onLost = (event) => {
        event.preventDefault();
        lost = true;
        if (frame !== null) cancelAnimationFrame(frame);
        frame = null;
        container.classList.remove('has-webgl');
    };
    const onRestored = () => {
        lost = false;
        schedule();
    };
    window.addEventListener('pointermove', onPointer, { passive: true });
    window.addEventListener('voca:motion', onMotion);
    document.addEventListener('visibilitychange', onVisibility);
    renderer.domElement.addEventListener('webglcontextlost', onLost);
    renderer.domElement.addEventListener('webglcontextrestored', onRestored);
    window.addEventListener('pagehide', (event) => {
        if (frame !== null) cancelAnimationFrame(frame);
        frame = null;
        if (event.persisted) return;
        disposed = true;
        resize.disconnect();
        visibility.disconnect();
        window.removeEventListener('pointermove', onPointer);
        window.removeEventListener('voca:motion', onMotion);
        document.removeEventListener('visibilitychange', onVisibility);
        scene.traverse((object) => {
            object.geometry?.dispose();
            if (object.material) {
                const materials = Array.isArray(object.material)
                    ? object.material
                    : [object.material];
                materials.forEach((material) => {
                    material.map?.dispose();
                    material.dispose();
                });
            }
        });
        environment.dispose();
        renderer.dispose();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) schedule();
    });
    schedule();
}
