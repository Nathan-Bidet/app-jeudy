import FileList from '@/Components/Directory/FileList';
import FileUploader from '@/Components/Directory/FileUploader';
import Modal from '@/Components/Modal';

export default function EntityFilesModal({ open, onClose, title, files = [], uploadUrl = null }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-sm font-black uppercase tracking-[0.08em]">
                    Documents {title ? `- ${title}` : ''}
                </h3>
            </div>

            <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4">
                {uploadUrl ? <FileUploader uploadUrl={uploadUrl} canUpload allowCustomName /> : null}
                <FileList files={files} canDelete />
            </div>

            <div className="flex justify-end border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                >
                    Fermer
                </button>
            </div>
        </Modal>
    );
}
