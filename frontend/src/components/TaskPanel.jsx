import "../styles/taskpanel.css";

export default function TaskPanel() {
  return (
    <div className="task-panel">
      <button className="new-task">
        <img src="edit_icon_black.png"></img>
        <p>New Task</p>
      </button>

      <div className="task-list">
        {[1,2,3,4].map(i => (
          <div key={i} className="task-item">
            <span>List item</span>
            <input type="checkbox" />
          </div>
        ))}
      </div>
    </div>
  );
}