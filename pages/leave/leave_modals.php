<?php
/* leave_modals.php - included in leave_manage.php */

/* No PHP logic needed here unless you want dynamic content. JS will handle modals */
?>

<!-- View Leave Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Leave Application Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-center my-3">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p>Loading details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="approveForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="approveModalTitle">Approve Leave Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="LeaveApplicationID" id="approveAppId">
                    <input type="hidden" name="action" id="approveAction">
                    <div class="mb-3">
                        <label for="approveComment" class="form-label">Comments (Optional)</label>
                        <textarea class="form-control" id="approveComment" name="comment" rows="3"
                                  placeholder="Enter any comments..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="rejectForm">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Leave Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="LeaveApplicationID" id="rejectAppId">
                    <input type="hidden" name="action" value="reject">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> Please provide a reason for rejection.
                    </div>
                    <div class="mb-3">
                        <label for="rejectReason" class="form-label">Rejection Reason:</label>
                        <textarea class="form-control" id="rejectReason" name="comment" rows="4"
                                  placeholder="Enter the reason for rejection..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ======== JS Handlers for Leave Modals ========

// View Details
function showViewModal(appId) {
    const modalBody = document.getElementById('viewModalBody');
    modalBody.innerHTML = `
        <div class="text-center my-3">
            <div class="spinner-border text-primary" role="status"></div>
            <p>Loading details...</p>
        </div>
    `;

    // Fetch details via AJAX if needed
    // Example placeholder (replace with actual AJAX call)
    setTimeout(() => {
        modalBody.innerHTML = `
            <h6>Application Details for #${appId}</h6>
            <p>Detailed leave information can be loaded here.</p>
        `;
    }, 500);

    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();
}

// Approve Modal
function showApproveModal(appId, type) {
    const modalTitle = document.getElementById('approveModalTitle');
    const actionField = document.getElementById('approveAction');
    const appIdField = document.getElementById('approveAppId');

    modalTitle.textContent = type === 'l1' ? 'Approve L1' : 'Approve L2 (Final)';
    actionField.value = type === 'l1' ? 'l1_approve' : 'l2_approve';
    appIdField.value = appId;

    const modal = new bootstrap.Modal(document.getElementById('approveModal'));
    modal.show();
}

// Reject Modal
function showRejectModal(appId) {
    document.getElementById('rejectAppId').value = appId;
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
    setTimeout(() => document.getElementById('rejectReason').focus(), 300);
}

// Cancel Action
function confirmCancel(appId) {
    if (confirm('Are you sure you want to cancel this leave application?')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '';

        const inputs = [
            {name: 'LeaveApplicationID', value: appId},
            {name: 'action', value: 'cancel'},
            {name: 'comment', value: 'Cancelled by applicant'}
        ];

        inputs.forEach(i => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = i.name;
            input.value = i.value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
}

// Reject form validation
document.getElementById('rejectForm').addEventListener('submit', function(e) {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) {
        e.preventDefault();
        alert('Please enter a rejection reason.');
        document.getElementById('rejectReason').focus();
    }
});
</script>
