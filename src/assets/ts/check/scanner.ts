// Camera QR scanner (qr-scanner: native BarcodeDetector where available, otherwise a worker; works on iOS).

import QrScanner from 'qr-scanner';

const CameraKey = 'check-camera';

export interface Camera {
  id: string;
  label: string;
}

export interface Scanner {
  start(): Promise<void>;
  pause(): void;
  resume(): Promise<void>;
  cameras(): Promise<Camera[]>;
  /** Id of the chosen camera, null = the default back camera. */
  camera(): string | null;
  setCamera(id: string): Promise<void>;
  hasTorch(): Promise<boolean>;
  toggleTorch(): Promise<boolean>;
  destroy(): void;
}

/** Scans into the given element (video + highlighted scan region); onText gets every decoded text. */
export function createScanner(container: HTMLElement, onText: (text: string) => void): Scanner {
  const video = document.createElement('video');
  video.setAttribute('playsinline', 'true');
  video.muted = true;
  container.appendChild(video);

  const saved = readCamera();
  const qr = new QrScanner(video, (result) => onText(result.data), {
    returnDetailedScanResult: true,
    highlightScanRegion: true,
    highlightCodeOutline: true,
    maxScansPerSecond: 10,
    preferredCamera: saved ?? 'environment',
  });

  return {
    start: () => qr.start(),
    pause: () => void qr.pause(),
    resume: () => qr.start(),
    cameras: async () => (await QrScanner.listCameras(true)).map((camera) => ({ id: camera.id, label: camera.label })),
    camera: readCamera,
    setCamera: async (id) => {
      writeCamera(id);
      await qr.setCamera(id);
    },
    hasTorch: () => qr.hasFlash(),
    toggleTorch: async () => {
      await qr.toggleFlash();
      return qr.isFlashOn();
    },
    destroy: () => qr.destroy(),
  };
}

function readCamera(): string | null {
  try {
    return localStorage.getItem(CameraKey);
  } catch {
    return null;
  }
}

function writeCamera(id: string): void {
  try {
    localStorage.setItem(CameraKey, id);
  } catch {
    // Not remembered (private mode); the default camera is used next time.
  }
}
