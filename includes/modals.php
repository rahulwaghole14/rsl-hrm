<!-- Employee Apply Leave Modal -->
<div id="leaveModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('leaveModal')">&times;</span>
        <h2 style="margin-bottom: 1.5rem; color: var(--primary-color);">Apply for Leave</h2>
        <form action="apply_leave.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="leave_date" id="modalLeaveDate">

            <div class="form-group">
                <label>Subject <span style="color:red">*</span></label>
                <input type="text" name="subject" required placeholder="e.g. Sick Leave, Vacation">
            </div>

            <div class="form-group">
                <label>Description <span style="color:red">*</span></label>
                <textarea name="description" required
                    style="width:100%; min-height:100px; padding:0.75rem; border:1px solid var(--border-color); border-radius:0.5rem;"
                    placeholder="Reason for leave..."></textarea>
            </div>

            <div class="form-group">
                <label>Attachment (Optional)</label>
                <input type="file" name="attachment">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:1rem;">Send Request</button>
        </form>
    </div>
</div>

<!-- Admin View Leave Modal -->
<div id="adminLeaveModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('adminLeaveModal')">&times;</span>
        <h2 style="margin-bottom: 0.5rem; color: var(--primary-color);">Leave Request Details</h2>
        <p id="leaveStatusBadge" style="display:inline-block; margin-bottom:1.5rem;"></p>

        <div id="leaveDetailContent">
            <p><strong>Employee:</strong> <span id="viewEmpName"></span></p>
            <p><strong>Date:</strong> <span id="viewLeaveDate"></span></p>
            <p><strong>Subject:</strong> <span id="viewSubject"></span></p>
            <hr style="margin:1rem 0; border:0; border-top:1px solid var(--border-color);">
            <p><strong>Description:</strong></p>
            <p id="viewDescription" style="background:#f8fafc; padding:1rem; border-radius:0.5rem; margin-top:0.5rem;">
            </p>

            <div id="viewAttachmentArea" style="margin-top:1rem;">
                <strong>Attachment:</strong> <a id="viewAttachmentLink" href="#" target="_blank"
                    style="color:var(--primary-color);">View File</a>
                <span id="noAttachmentMsg" style="color:var(--text-muted);">No attachment</span>
            </div>
        </div>

        <div id="adminActions" style="display: flex; gap: 1rem; margin-top: 2rem;">
            <form action="process_leave.php" method="POST" style="flex:1;">
                <input type="hidden" name="leave_id" id="processLeaveId">
                <input type="hidden" name="status" value="approved">
                <button type="submit" class="btn"
                    style="background:#16a34a; color:white; width:100%; border:none;">Approve</button>
            </form>
            <form action="process_leave.php" method="POST" style="flex:1;">
                <input type="hidden" name="leave_id" id="processLeaveIdReject">
                <input type="hidden" name="status" value="rejected">
                <button type="submit" class="btn"
                    style="background:#ef4444; color:white; width:100%; border:none;">Reject</button>
            </form>
        </div>
    </div>
</div>

<style>
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
    }

    .modal-content {
        background-color: white;
        margin: 10% auto;
        padding: 2.5rem;
        border-radius: 1.5rem;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .close {
        position: absolute;
        right: 1.5rem;
        top: 1rem;
        color: #aaa;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover {
        color: black;
    }

    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 2rem;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .status-pending {
        background: #fef9c3;
        color: #a16207;
    }

    .status-approved {
        background: #dcfce7;
        color: #16a34a;
    }

    .status-rejected {
        background: #fee2e2;
        color: #ef4444;
    }
</style>

<script>
    function openLeaveModal(date) {
        document.getElementById('modalLeaveDate').value = date;
        document.getElementById('leaveModal').style.display = 'block';
    }

    function openAdminViewModal(data) {
        document.getElementById('viewEmpName').innerText = data.user_name;
        document.getElementById('viewLeaveDate').innerText = data.leave_date;
        document.getElementById('viewSubject').innerText = data.subject;
        document.getElementById('viewDescription').innerText = data.description;
        document.getElementById('processLeaveId').value = data.id;
        document.getElementById('processLeaveIdReject').value = data.id;

        const badge = document.getElementById('leaveStatusBadge');
        badge.innerText = data.status.toUpperCase();
        badge.className = 'status-badge status-' + data.status;

        const attachmentLink = document.getElementById('viewAttachmentLink');
        const noAttachmentMsg = document.getElementById('noAttachmentMsg');
        if (data.attachment) {
            attachmentLink.href = 'uploads/leaves/' + data.attachment;
            attachmentLink.style.display = 'inline';
            noAttachmentMsg.style.display = 'none';
        } else {
            attachmentLink.style.display = 'none';
            noAttachmentMsg.style.display = 'inline';
        }

        // Hide actions if already processed
        document.getElementById('adminActions').style.display = (data.status === 'pending') ? 'flex' : 'none';

        document.getElementById('adminLeaveModal').style.display = 'block';
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    window.onclick = function (event) {
        if (event.target.className === 'modal') {
            event.target.style.display = "none";
        }
    }
</script>