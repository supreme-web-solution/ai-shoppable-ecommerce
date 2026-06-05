const pendingUploads = new Map<number, File>();

export function setPendingVideoUpload(videoId: number, file: File): void {
    pendingUploads.set(videoId, file);
}

export function takePendingVideoUpload(videoId: number): File | null {
    const file = pendingUploads.get(videoId) ?? null;
    pendingUploads.delete(videoId);

    return file;
}
